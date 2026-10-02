<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\TokenBase\Model;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface;
use SalesIgniter\Common\Model\Payment\OffSession\Clock;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface;

/**
 * ParadoxLabs TokenBase off-session charges: Authorize.Net CIM, CyberSource, and any other TokenBase
 * gateway. TokenBase keeps cards in its own table (paradoxlabs_stored_card), not in Magento_Vault, so
 * the subject remembers the card id in payment_data.tokenbase_card_id.
 *
 * A later charge pays with the same method and card: importData(['card_id' => card hash]) plus
 * is_subscription_generated = 1, which makes TokenBase skip the CVV and send the gateway's recurring
 * flags (Authorize.Net recurringBilling; CyberSource merchant-initiated recurring). Synchronous.
 */
class TokenBaseStrategy implements StrategyInterface
{
    public const CODE = 'tokenbase';

    /** @var CardRepositoryInterface */
    private $cards;
    /** @var PaymentHelper */
    private $paymentHelper;
    /** @var Clock */
    private $time;

    public function __construct(CardRepositoryInterface $cards, PaymentHelper $paymentHelper, Clock $time)
    {
        $this->cards = $cards;
        $this->paymentHelper = $paymentHelper;
        $this->time = $time;
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function captureFromOrder(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
        $payment = $order->getPayment();
        $method = (string)$payment->getMethod();
        $cardId = (int)$payment->getData('tokenbase_id');
        $extension = $payment->getExtensionAttributes();
        if (!$cardId && $extension !== null && method_exists($extension, 'getTokenbaseId')) {
            $cardId = (int)$extension->getTokenbaseId();
        }
        $subject->setPaymentMethod($method);
        $subject->setVaultTokenId(null);
        $subject->setPaymentData([
            'strategy' => self::CODE,
            'checkout_method' => $method,
            'tokenbase_card_id' => $cardId ?: null,
        ]);
    }

    public function readiness(ChargeSubjectInterface $subject, Quote $quote): ?array
    {
        $card = $this->card($subject);
        if ($card === null) {
            return [Decline::ACTION_REQUIRED, (string)__('No saved card. Please pay and save a card.')];
        }
        if (!(int)$card->getActive()) {
            return [Decline::ACTION_REQUIRED, (string)__('The saved card was removed. Please choose another one.')];
        }
        if ((int)$card->getCustomerId() !== (int)$subject->getCustomerId()) {
            return [Decline::CONFIG, (string)__('The saved card belongs to another customer.')];
        }
        $expires = (string)$card->getExpires();
        if ($expires !== '' && $expires < $this->time->nowString()) {
            return [Decline::HARD, (string)__('The saved card has expired.')];
        }
        try {
            $method = $this->paymentHelper->getMethodInstance($subject->getPaymentMethod());
            $method->setStore($subject->getStoreId());
            if (!(int)$method->getConfigData('active', $subject->getStoreId())) {
                return [Decline::CONFIG, (string)__('The payment method %1 is not available.', $subject->getPaymentMethod())];
            }
        } catch (\Throwable $e) {
            return [Decline::CONFIG, (string)__('The payment method %1 is not available.', $subject->getPaymentMethod())];
        }
        return null;
    }

    public function configureQuote(ChargeSubjectInterface $subject, Quote $quote): void
    {
        $card = $this->card($subject);
        $payment = $quote->getPayment();
        $payment->setQuote($quote);
        // cron charges run in an emulated frontend area, where TokenBase loads a card by its hash only
        $payment->importData([
            'method' => $subject->getPaymentMethod(),
            'card_id' => $card !== null ? (string)$card->getHash() : '',
        ]);
        $payment->setAdditionalInformation('is_subscription_generated', 1);
        $payment->setAdditionalInformation(self::PAYMENT_FLAG, 'true');
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
    }

    public function card(ChargeSubjectInterface $subject): ?CardInterface
    {
        $id = (int)($subject->getPaymentData()['tokenbase_card_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        try {
            return $this->cards->getById($id);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
