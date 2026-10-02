<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

/**
 * A classified failure of an off-session charge:
 *  - hard: stolen, closed, expired or invalid card. Stop retrying; ask for a new card.
 *  - soft: insufficient funds, issuer unavailable, generic decline. Follow the retry schedule.
 *  - action_required: 3DS / authentication_required. Send the customer a payment link at once.
 *  - config: the gateway is disabled, the token is missing, keys are wrong. Alert the admin; retry.
 * A revocation (the customer told their bank to stop paying) is hard and also ends the agreement.
 */
final class Decline
{
    public const HARD = 'hard';
    public const SOFT = 'soft';
    public const ACTION_REQUIRED = 'action_required';
    public const CONFIG = 'config';

    /** @var string */
    private $class;
    /** @var string */
    private $code;
    /** @var string */
    private $message;
    /** @var bool */
    private $revoked;

    public function __construct(string $class, string $code, string $message, bool $revoked = false)
    {
        $this->class = in_array($class, [self::HARD, self::SOFT, self::ACTION_REQUIRED, self::CONFIG], true) ? $class : self::SOFT;
        $this->code = substr($code, 0, 48);
        $this->message = $message;
        $this->revoked = $revoked;
    }

    public function getClass(): string
    {
        return $this->class;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    /** "hard:2004": the class and code in one short string (what a schedule or balance row keeps) */
    public function asScheduleCode(): string
    {
        return substr($this->class . ($this->code !== '' ? ':' . $this->code : ''), 0, 64);
    }
}
