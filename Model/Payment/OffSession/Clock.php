<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * "Now" in UTC for the off-session layer (token expiry, card expiry), in one place so tests can
 * freeze it. Every datetime this layer writes is UTC 'Y-m-d H:i:s'.
 */
class Clock
{
    /** @var DateTime */
    private $dateTime;

    /** @var string|null frozen "now", UTC */
    private $frozen;

    public function __construct(DateTime $dateTime)
    {
        $this->dateTime = $dateTime;
    }

    public function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->frozen ?? $this->dateTime->gmtDate('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    public function nowString(): string
    {
        return $this->nowUtc()->format('Y-m-d H:i:s');
    }

    /** Freeze "now" (UTC 'Y-m-d H:i:s'); null unfreezes. For tests only. */
    public function freeze(?string $utc): void
    {
        $this->frozen = $utc;
    }
}
