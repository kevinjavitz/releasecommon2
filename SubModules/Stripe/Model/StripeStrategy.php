<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Stripe\Model;

use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\PaymentDeclinedException;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface;
use SalesIgniter\Common\Model\Payment\OffSession\SubjectSaverInterface;
use StripeIntegration\Payments\Model\Checkout\Flow;
use StripeIntegration\Payments\Model\Config as StripeConfig;

/**
 * Off-session charges with the official Stripe module. The module keeps no Magento_Vault tokens: the
 * card is a Stripe PaymentMethod on the customer's Stripe Customer, saved for off-session use at
 * checkout (Plugin\SaveCardForOffSession).
 *
 * A charge comes first, then the order: an off-session PaymentIntent (off_session + confirm,
 * merchant-initiated) for the quote's total; on success the Stripe module's own "order from charge"
 * path records it (Checkout\Flow::$creatingOrderFromCharge), so transactions, invoices, refunds from
 * a credit memo and its webhooks work as for any Stripe order. The PaymentIntent then gets the order
 * number in its metadata.
 *
 * - idempotency key: <subject reference>-<quote id> (subscriptions: sisub-<id>-<cycle>-<quote>);
 * - authentication_required / requires_action: action_required, the customer confirms through a
 *   payment link (SCA);
 * - a charge that succeeded but whose order failed is reused for the next attempt with the same
 *   subject reference (payment_data.stripe_pending, saved at once through SubjectSaverInterface),
 *   never charged twice.
 *
 * The Stripe module's Checkout\Flow and Config are taken from the object manager by name in the constructor,
 * the same shared instances constructor injection gave, so no Stripe type is in the constructor:
 * setup:di:compile reads every constructor under SubModules/*, also on stores without Stripe, where this
 * sub-module is not registered (Test/Unit/Architecture/SubModulesCompileSafeTest).
 */
class StripeStrategy implements StrategyInterface
{
    public const CODE = 'stripe';
    public const METHOD = 'stripe_payments';

    /** @var StripeGateway */
    private $gateway;
    /** @var Flow */
    private $flow;
    /** @var StripeConfig */
    private $stripeConfig;
    /** @var SubjectSaverInterface */
    private $saver;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(StripeGateway $gateway, ObjectManagerInterface $objectManager, SubjectSaverInterface $saver, LoggerInterface $logger)
    {
        $this->gateway = $gateway;
        $this->flow = $objectManager->get(Flow::class);
        $this->stripeConfig = $objectManager->get(StripeConfig::class);
        $this->saver = $saver;
        $this->logger = $logger;
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function captureFromOrder(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
        $payment = $order->getPayment();
        $intent = (string)($payment->getLastTransId() ?: $payment->getAdditionalInformation('payment_intent_id'));
        $intent = preg_replace('/-(capture|refund|void).*$/', '', $intent);
        $data = [
            'strategy' => self::CODE,
            'checkout_method' => (string)$payment->getMethod(),
            'stripe_customer_id' => (string)$payment->getAdditionalInformation('customer_stripe_id') ?: null,
            'stripe_payment_intent' => strpos((string)$intent, 'pi_') === 0 ? $intent : null,
        ];
        $token = (string)$payment->getAdditionalInformation('token');
        if (strpos($token, 'pm_') === 0) {
            $data['stripe_payment_method'] = $token;
        }
        $subject->setPaymentMethod(self::METHOD);
        $subject->setVaultTokenId(null);
        $subject->setPaymentData($data);
    }

    public function readiness(ChargeSubjectInterface $subject, Quote $quote): ?array
    {
        if (!$this->saver->supports($subject)) {
            return [Decline::CONFIG, (string)__('Nothing can save the payment details of %1.', $subject->getOffSessionReference())];
        }
        try {
            [$customer, $method] = $this->resolve($subject);
        } catch (\Throwable $e) {
            return [Decline::CONFIG, (string)__('Stripe could not be reached: %1', $e->getMessage())];
        }
        if ($customer === '' || $method === '') {
            return [Decline::ACTION_REQUIRED, (string)__('No saved card at Stripe. Please pay and save a card.')];
        }
        if (!$this->methodActive($subject)) {
            return [Decline::CONFIG, (string)__('Stripe is not enabled for this store.')];
        }
        return null;
    }

    public function configureQuote(ChargeSubjectInterface $subject, Quote $quote): void
    {
        if (!$this->saver->supports($subject)) {
            $message = (string)__('Nothing can save the payment details of %1.', $subject->getOffSessionReference());
            throw new PaymentDeclinedException(__('%1', $message), new Decline(Decline::CONFIG, 'no_saver', $message));
        }
        [$customer, $method] = $this->resolve($subject);
        $payment = $quote->getPayment();
        $payment->setQuote($quote);
        $payment->importData(['method' => self::METHOD]);
        $payment->setAdditionalInformation('token', $method);
        $payment->setAdditionalInformation('customer_stripe_id', $customer);
        $payment->setAdditionalInformation(self::PAYMENT_FLAG, 'true');
        $quote->setTotalsCollectedFlag(false)->collectTotals();

        $reference = $subject->getOffSessionReference();
        $data = $subject->getPaymentData();
        $pending = (array)($data['stripe_pending'] ?? []);
        $intent = null;
        if (($pending['reference'] ?? null) === $reference && !empty($pending['id'])) {
            // charged earlier for this reference but no order was placed: reuse that charge
            try {
                $previous = $this->gateway->retrievePaymentIntent((string)$pending['id'], $subject->getStoreId());
                if (in_array($previous->status, ['succeeded', 'processing', 'requires_capture'], true)
                    && abs((int)$previous->amount - (int)($pending['amount'] ?? -1)) === 0) {
                    $intent = $previous;
                }
            } catch (\Throwable $e) {
                $intent = null;
            }
        }
        if ($intent === null) {
            $intent = $this->gateway->chargeOffSession(
                $customer,
                $method,
                (float)$quote->getGrandTotal(),
                (string)$quote->getQuoteCurrencyCode(),
                $subject->getOffSessionDescription(),
                array_map('strval', $subject->getOffSessionMetadata()),
                $reference . '-' . (int)$quote->getId(),
                $subject->getStoreId()
            );
        }
        $status = (string)$intent->status;
        if ($status === 'requires_action' || $status === 'requires_confirmation') {
            throw new PaymentDeclinedException(
                __('The card issuer asked the customer to confirm the payment.'),
                new Decline(Decline::ACTION_REQUIRED, 'authentication_required', (string)__('The card issuer asked the customer to confirm the payment.'))
            );
        }
        if (!in_array($status, ['succeeded', 'processing', 'requires_capture'], true)) {
            $message = (string)($intent->last_payment_error->message ?? __('The payment was not completed.'));
            throw new PaymentDeclinedException(__('%1', $message), new Decline(Decline::SOFT, (string)($intent->last_payment_error->decline_code ?? $status), $message));
        }
        $data['stripe_pending'] = ['reference' => $reference, 'id' => (string)$intent->id, 'amount' => (int)$intent->amount];
        $subject->setPaymentData($data);
        $this->saver->save($subject);
        // the Stripe module records this charge on the order instead of charging again
        $this->flow->creatingOrderFromCharge = ['payment_intent' => (string)$intent->id];
    }

    public function isAsync(ChargeSubjectInterface $subject): bool
    {
        return false;
    }

    public function isManual(ChargeSubjectInterface $subject): bool
    {
        return false;
    }

    public function afterOrderPlaced(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
        $this->flow->creatingOrderFromCharge = null;
        $data = $subject->getPaymentData();
        $intent = (string)($data['stripe_pending']['id'] ?? '');
        unset($data['stripe_pending']);
        $subject->setPaymentData($data);
        if ($intent === '') {
            return;
        }
        try {
            // the Stripe module's webhooks and dashboard links find orders by this metadata
            $this->gateway->updatePaymentIntent($intent, [
                'description' => (string)__('Order #%1 (%2)', $order->getIncrementId(), $subject->getOffSessionDescription()),
                'metadata' => ['Order #' => (string)$order->getIncrementId()],
            ], $subject->getStoreId());
        } catch (\Throwable $e) {
            $this->logger->warning('[sicommon] Stripe: order number not written to ' . $intent . ': ' . $e->getMessage());
        }
    }

    /**
     * The Stripe customer and payment method to charge; the payment method is looked up from the
     * first order's PaymentIntent the first time.
     *
     * @return array{0: string, 1: string}
     */
    public function resolve(ChargeSubjectInterface $subject): array
    {
        $data = $subject->getPaymentData();
        $customer = (string)($data['stripe_customer_id'] ?? '');
        $method = (string)($data['stripe_payment_method'] ?? '');
        if (($customer === '' || $method === '') && !empty($data['stripe_payment_intent'])) {
            $intent = $this->gateway->retrievePaymentIntent((string)$data['stripe_payment_intent'], $subject->getStoreId());
            $customer = $customer !== '' ? $customer : (string)$intent->customer;
            $method = $method !== '' ? $method : (string)(is_object($intent->payment_method) ? $intent->payment_method->id : $intent->payment_method);
            $data['stripe_customer_id'] = $customer ?: null;
            $data['stripe_payment_method'] = $method ?: null;
            $subject->setPaymentData($data);
        }
        return [$customer, $method];
    }

    private function methodActive(ChargeSubjectInterface $subject): bool
    {
        return (bool)(int)$this->stripeConfig->getConfigData('active', null, $subject->getStoreId());
    }
}
