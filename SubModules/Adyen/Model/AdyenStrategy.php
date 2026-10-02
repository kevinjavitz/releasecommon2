<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Adyen\Model;

use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\PaymentDeclinedException;
use SalesIgniter\Common\Model\Payment\OffSession\Strategy\VaultStrategy;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface;

/**
 * Adyen off-session charges: Adyen tokens are Magento_Vault tokens (adyen_cc_vault and the APM
 * *_vault methods), so the charge itself is the vault strategy; what differs is the answer.
 *
 * - "Authorised" still waits for Adyen's AUTHORISATION webhook before the order is paid, so the
 *   strategy is asynchronous: the caller waits until the invoice.
 * - "RedirectShopper" / "ChallengeShopper" / "IdentifyShopper": the issuer wants the shopper. The
 *   order is cancelled and the charge becomes action_required (payment link).
 * - "Pending" / "Received" (SEPA and other delayed methods): waiting, like Authorised.
 * - "Refused" throws inside the order placement, so no order exists; StashResponse keeps the code.
 * - Tokens can arrive by the RECURRING_CONTRACT webhook after checkout: VaultStrategy looks the
 *   token up by the first order's payment id when the subject has none yet.
 */
class AdyenStrategy implements StrategyInterface
{
    public const CODE = 'adyen';

    public const SHOPPER_ACTION = ['RedirectShopper', 'ChallengeShopper', 'IdentifyShopper', 'PresentToShopper'];

    /** @var VaultStrategy */
    private $vault;
    /** @var OrderManagementInterface */
    private $orders;

    public function __construct(VaultStrategy $vault, OrderManagementInterface $orders)
    {
        $this->vault = $vault;
        $this->orders = $orders;
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function captureFromOrder(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
        $this->vault->captureFromOrder($subject, $order);
        $subject->setPaymentData(['strategy' => self::CODE] + $subject->getPaymentData());
    }

    public function readiness(ChargeSubjectInterface $subject, Quote $quote): ?array
    {
        return $this->vault->readiness($subject, $quote);
    }

    public function configureQuote(ChargeSubjectInterface $subject, Quote $quote): void
    {
        $this->vault->configureQuote($subject, $quote);
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
        $result = (string)$order->getData('adyen_resulturl_event_code');
        if (!in_array($result, self::SHOPPER_ACTION, true)) {
            return;
        }
        try {
            $this->orders->cancel((int)$order->getEntityId());
        } catch (\Throwable $e) {
            // the charge is still reported as needing the customer; the admin sees the order state
        }
        $message = (string)__('The card issuer asked the customer to confirm the payment (%1).', $result);
        throw new PaymentDeclinedException(__('%1', $message), new Decline(Decline::ACTION_REQUIRED, $result, $message));
    }
}
