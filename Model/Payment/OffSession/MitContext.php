<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

/**
 * Request-scoped: "a merchant-initiated charge is in progress". Set only around the order placement
 * of an off-session charge (a subscription renewal, a booking balance); read by the gateway
 * sub-modules' plugins (recurring / unscheduled MIT flags, no 3DS demand, stored-credential flags)
 * and by observers (Observer\OffSession\SuppressMitOrderEmail; the consumers' own).
 *
 * Also carries the raw gateway result a gateway plugin stashes for DeclineClassifier. That result
 * survives the end of run(): the caller classifies a failure after run() has thrown.
 *
 * $variableAmount: true when the amount is not a fixed instalment of an agreed schedule. Gateways
 * then send the charge as unscheduled card-on-file (Braintree "unscheduled", Adyen
 * "UnscheduledCardOnFile") instead of recurring. A one-off later charge (a booking balance) runs
 * with true.
 */
class MitContext
{
    /** context key: true suppresses the new-order email of the order placed inside run() */
    public const SUPPRESS_ORDER_EMAIL = 'suppress_order_email';

    /** @var ChargeSubjectInterface|null */
    private $subject;

    /** @var bool */
    private $variableAmount = false;

    /** @var array<string, mixed> */
    private $context = [];

    /** @var array<string, mixed> */
    private $gatewayResult = [];

    /**
     * Run $work as a merchant-initiated charge of $subject; the context is restored afterwards,
     * whatever happens (nesting is allowed). The gateway result stash is cleared on entry only.
     *
     * @param array<string, mixed> $context free keys for observers and plugins (SUPPRESS_ORDER_EMAIL...)
     * @return mixed what $work returns
     */
    public function run(ChargeSubjectInterface $subject, bool $variableAmount, callable $work, array $context = [])
    {
        $previous = [$this->subject, $this->variableAmount, $this->context];
        $this->subject = $subject;
        $this->variableAmount = $variableAmount;
        $this->context = $context;
        $this->gatewayResult = [];
        try {
            return $work();
        } finally {
            [$this->subject, $this->variableAmount, $this->context] = $previous;
        }
    }

    public function isActive(): bool
    {
        return $this->subject !== null;
    }

    public function subject(): ?ChargeSubjectInterface
    {
        return $this->subject;
    }

    public function isVariableAmount(): bool
    {
        return $this->variableAmount;
    }

    /**
     * The context array given to run(), or one key of it ($default when absent or not running).
     *
     * @return mixed
     */
    public function context(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->context;
        }
        return array_key_exists($key, $this->context) ? $this->context[$key] : $default;
    }

    /** true when the order placed inside the current run() must not send its new-order email */
    public function suppressesOrderEmail(): bool
    {
        return $this->isActive() && (bool)$this->context(self::SUPPRESS_ORDER_EMAIL, false);
    }

    /** Called by a gateway plugin with the raw result of its last request (codes, statuses). */
    public function stashGatewayResult(array $result): void
    {
        $this->gatewayResult = $result;
    }

    /** @return array<string, mixed> */
    public function gatewayResult(): array
    {
        return $this->gatewayResult;
    }

    public function clearGatewayResult(): void
    {
        $this->gatewayResult = [];
    }
}
