<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * How one family of payment methods keeps a card at checkout and charges it later without the
 * customer (a subscription renewal, a booking balance).
 *
 * Common ships vault (Magento_Vault: Braintree, Payflow Pro and third-party vault methods), offline
 * and free. The gateway sub-modules (SubModules/*) add theirs (Stripe off-session PaymentIntents,
 * Mollie mandates, Adyen, ParadoxLabs TokenBase) through StrategyPool's `strategies` argument and map
 * their checkout codes in OffSessionMethods' `extraMethods`.
 *
 * The caller's sequence: readiness() -> configureQuote() -> MitContext::run(submit the quote, then
 * afterOrderPlaced()) -> isAsync() / isManual() decide what the placed order means.
 */
interface StrategyInterface
{
    /** additional information the strategies set on the payment of an off-session order ('true') */
    public const PAYMENT_FLAG = 'sicommon_off_session';

    public function getCode(): string;

    /**
     * After the checkout order is placed: remember how to charge again (vault token id, mandate id...).
     * Sets the payment method, vault token id and payment data on $subject; does not save it.
     */
    public function captureFromOrder(ChargeSubjectInterface $subject, OrderInterface $order): void;

    /**
     * Why $subject cannot be charged off-session right now (no token, token expired or inactive,
     * method disabled), or null when it can. A reason becomes a "config" or "action_required" decline
     * without contacting the gateway.
     *
     * @return array{0: string, 1: string}|null [Decline class, translated reason]
     */
    public function readiness(ChargeSubjectInterface $subject, Quote $quote): ?array;

    /**
     * Set the quote's payment (method code and additional information). A strategy that charges
     * before the order exists (Stripe) charges here and throws PaymentDeclinedException on a decline.
     */
    public function configureQuote(ChargeSubjectInterface $subject, Quote $quote): void;

    /**
     * true when the gateway confirms later (webhook): the order is placed pending_payment and the
     * caller waits until it is paid.
     */
    public function isAsync(ChargeSubjectInterface $subject): bool;

    /** true when an order placed with this strategy is not paid yet (offline methods). */
    public function isManual(ChargeSubjectInterface $subject): bool;

    /**
     * Right after the order is placed, still inside MitContext::run(): start a gateway transaction
     * the order placement does not start by itself (Mollie), or write the order number to the charge
     * (Stripe). May throw PaymentDeclinedException (Adyen: the issuer wants the shopper).
     */
    public function afterOrderPlaced(ChargeSubjectInterface $subject, OrderInterface $order): void;
}
