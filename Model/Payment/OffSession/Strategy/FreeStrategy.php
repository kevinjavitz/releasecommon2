<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession\Strategy;

use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface;

/**
 * A charge whose total is zero (a 100% coupon, a free plan, store credit covering it): Magento's own
 * "free" method. The caller switches to it whenever the quote totals 0, whatever the subject
 * normally pays with, so no gateway is ever asked to charge 0.
 */
class FreeStrategy implements StrategyInterface
{
    public const CODE = 'free';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function captureFromOrder(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
        $subject->setPaymentMethod('free');
        $subject->setVaultTokenId(null);
        $subject->setPaymentData(['strategy' => self::CODE]);
    }

    public function readiness(ChargeSubjectInterface $subject, Quote $quote): ?array
    {
        return null;
    }

    public function configureQuote(ChargeSubjectInterface $subject, Quote $quote): void
    {
        $payment = $quote->getPayment();
        $payment->setQuote($quote);
        $payment->importData(['method' => 'free']);
        $payment->setAdditionalInformation(self::PAYMENT_FLAG, 'true');
    }

    public function isAsync(ChargeSubjectInterface $subject): bool
    {
        return false;
    }

    public function afterOrderPlaced(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
    }

    public function isManual(ChargeSubjectInterface $subject): bool
    {
        return false;
    }
}
