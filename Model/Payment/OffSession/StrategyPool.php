<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Framework\Exception\LocalizedException;

/**
 * Picks the off-session strategy for a subject (by the strategy it was saved with, else by its
 * method) or for a checkout method (when the card is first kept).
 *
 * Strategies arrive in the `strategies` di argument: vault, offline and free from this module, the
 * gateways' from the SalesIgniter_CommonPay* sub-modules.
 */
class StrategyPool
{
    /** @var StrategyInterface[] code => strategy */
    private $strategies = [];

    /** @var OffSessionMethods */
    private $methods;

    public function __construct(OffSessionMethods $methods, array $strategies = [])
    {
        $this->methods = $methods;
        foreach ($strategies as $strategy) {
            if ($strategy instanceof StrategyInterface) {
                $this->strategies[$strategy->getCode()] = $strategy;
            }
        }
    }

    public function has(string $code): bool
    {
        return isset($this->strategies[$code]);
    }

    public function get(string $code): StrategyInterface
    {
        if (!isset($this->strategies[$code])) {
            throw new LocalizedException(__('No off-session payment strategy "%1" is installed.', $code));
        }
        return $this->strategies[$code];
    }

    /** The strategy for a checkout method code, or null when that method cannot be charged later. */
    public function forCheckoutMethod(string $methodCode, ?int $storeId = null): ?StrategyInterface
    {
        $code = $this->methods->strategyFor($methodCode, $storeId);
        return $code !== null && isset($this->strategies[$code]) ? $this->strategies[$code] : null;
    }

    /** The strategy $subject was saved with (payment_data.strategy), else the one its method maps to. */
    public function forSubject(ChargeSubjectInterface $subject): ?StrategyInterface
    {
        $stored = (string)($subject->getPaymentData()['strategy'] ?? '');
        if ($stored !== '' && isset($this->strategies[$stored])) {
            return $this->strategies[$stored];
        }
        return $this->forCheckoutMethod($subject->getPaymentMethod(), $subject->getStoreId());
    }
}
