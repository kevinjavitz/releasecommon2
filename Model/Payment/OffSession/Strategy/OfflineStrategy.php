<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession\Strategy;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface;

/**
 * Check / money order, bank transfer, cash on delivery, purchase order: the order is placed pending
 * and paid by hand (isManual). The consumer's email carries a "Pay online instead" link.
 */
class OfflineStrategy implements StrategyInterface
{
    public const CODE = 'offline';

    /** @var PaymentHelper */
    private $paymentHelper;

    public function __construct(PaymentHelper $paymentHelper)
    {
        $this->paymentHelper = $paymentHelper;
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function captureFromOrder(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
        $payment = $order->getPayment();
        $subject->setPaymentMethod((string)$payment->getMethod());
        $subject->setVaultTokenId(null);
        $info = [];
        if ((string)$payment->getMethod() === 'purchaseorder' && $payment->getPoNumber()) {
            $info['po_number'] = (string)$payment->getPoNumber();
        }
        $subject->setPaymentData(['strategy' => self::CODE] + $info);
    }

    public function readiness(ChargeSubjectInterface $subject, Quote $quote): ?array
    {
        try {
            $method = $this->paymentHelper->getMethodInstance($subject->getPaymentMethod());
            $method->setStore((int)$quote->getStoreId());
            if (!$method->isActive((int)$quote->getStoreId())) {
                return [Decline::CONFIG, (string)__('The payment method %1 is disabled.', $subject->getPaymentMethod())];
            }
        } catch (\Throwable $e) {
            return [Decline::CONFIG, (string)__('The payment method %1 is not available.', $subject->getPaymentMethod())];
        }
        return null;
    }

    public function configureQuote(ChargeSubjectInterface $subject, Quote $quote): void
    {
        $data = ['method' => $subject->getPaymentMethod()];
        $po = (string)($subject->getPaymentData()['po_number'] ?? '');
        if ($po !== '') {
            $data['po_number'] = $po;
        }
        $payment = $quote->getPayment();
        $payment->setQuote($quote);
        $payment->importData($data);
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
        return true;
    }
}
