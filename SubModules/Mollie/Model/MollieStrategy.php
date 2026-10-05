<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Mollie\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Payment\Service\Mollie\StartTransaction;
use SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface;

/**
 * Mollie off-session charges through mandates.
 *
 *  - First order: Mollie must send sequenceType=first and create the Mollie customer (the mandate).
 *    The consumer tells Mollie which orders need that (the subscriptions module's Mollie
 *    sub-module: an order that starts a subscription).
 *  - Later charge: the order is placed with the same Mollie method; Model\MitTransactionPart turns the
 *    transaction into sequenceType=recurring for the customer's mandate; afterOrderPlaced() starts it
 *    (Mollie starts transactions from its redirect controller otherwise). The result is asynchronous:
 *    the webhook invoices the order and the consumer's invoice observer settles its row.
 *
 * Mollie's StartTransaction is taken from the object manager by name when afterOrderPlaced() first needs it
 * (as the StartTransaction\Proxy this sub-module's di.xml used to inject did), so no Mollie type is in the
 * constructor: setup:di:compile reads every constructor under SubModules/*, also on stores without Mollie,
 * where this sub-module is not registered (Test/Unit/Architecture/SubModulesCompileSafeTest).
 */
class MollieStrategy implements StrategyInterface
{
    public const CODE = 'mollie';

    /** Magento method => Mollie method a mandate from that first payment charges */
    public const RECURRING_METHOD = [
        'mollie_methods_creditcard' => 'creditcard',
        'mollie_methods_applepay' => 'creditcard',
        'mollie_methods_paypal' => 'paypal',
        'mollie_methods_ideal' => 'directdebit',
        'mollie_methods_bancontact' => 'directdebit',
        'mollie_methods_eps' => 'directdebit',
        'mollie_methods_belfius' => 'directdebit',
        'mollie_methods_kbc' => 'directdebit',
        'mollie_methods_directdebit' => 'directdebit',
    ];

    /** @var CustomerRepositoryInterface */
    private $customers;

    /** @var ObjectManagerInterface */
    private $objectManager;

    /** @var StartTransaction|null */
    private $startTransaction;

    public function __construct(CustomerRepositoryInterface $customers, ObjectManagerInterface $objectManager)
    {
        $this->customers = $customers;
        $this->objectManager = $objectManager;
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function captureFromOrder(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
        $method = (string)$order->getPayment()->getMethod();
        $subject->setPaymentMethod($method);
        $subject->setVaultTokenId(null);
        $subject->setPaymentData([
            'strategy' => self::CODE,
            'checkout_method' => $method,
            'mollie_method' => self::RECURRING_METHOD[$method] ?? 'creditcard',
            'mollie_customer_id' => $this->mollieCustomerId((int)$order->getCustomerId()),
        ]);
    }

    public function readiness(ChargeSubjectInterface $subject, Quote $quote): ?array
    {
        $customerId = (string)($subject->getPaymentData()['mollie_customer_id'] ?? '')
            ?: $this->mollieCustomerId((int)$subject->getCustomerId());
        if ($customerId === '') {
            return [Decline::ACTION_REQUIRED, (string)__('No Mollie mandate yet: the first payment has to be made again.')];
        }
        if (!isset($subject->getPaymentData()['mollie_customer_id'])) {
            $subject->setPaymentData(['mollie_customer_id' => $customerId] + $subject->getPaymentData());
        }
        return null;
    }

    public function configureQuote(ChargeSubjectInterface $subject, Quote $quote): void
    {
        $payment = $quote->getPayment();
        $payment->setQuote($quote);
        $payment->importData(['method' => $subject->getPaymentMethod()]);
        $payment->setAdditionalInformation(self::PAYMENT_FLAG, 'true');
    }

    public function isAsync(ChargeSubjectInterface $subject): bool
    {
        return true;
    }

    public function isManual(ChargeSubjectInterface $subject): bool
    {
        return false;
    }

    public function afterOrderPlaced(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
        $this->startTransaction()->execute($order);
    }

    /** @return StartTransaction the shared instance, resolved on first use as the proxy did */
    private function startTransaction()
    {
        if ($this->startTransaction === null) {
            $this->startTransaction = $this->objectManager->get(StartTransaction::class);
        }
        return $this->startTransaction;
    }

    private function mollieCustomerId(int $customerId): string
    {
        if ($customerId <= 0) {
            return '';
        }
        try {
            $extension = $this->customers->getById($customerId)->getExtensionAttributes();
            return $extension && method_exists($extension, 'getMollieCustomerId') ? (string)$extension->getMollieCustomerId() : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
