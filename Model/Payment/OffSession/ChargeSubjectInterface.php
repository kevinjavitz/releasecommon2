<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

/**
 * What an off-session strategy charges: a subscription (releasesubscriptions2) or a booking balance
 * (releaserental2). The strategies read how to charge (method, saved card, gateway data) and write it
 * back after a checkout; they never know which module the subject belongs to.
 *
 * The consumer persists the subject itself; a strategy that must persist mid-charge (Stripe keeps a
 * charge that succeeded before the order failed) goes through SubjectSaverInterface.
 */
interface ChargeSubjectInterface
{
    /**
     * Names the amount being collected: the same value on every attempt at the same charge, a new
     * value for the next charge. It roots the gateway idempotency key and the "already charged, reuse
     * it" guard. Subscriptions: `sisub-<id>-<cycle>`; a booking balance row: `sirent-bal-<id>`.
     */
    public function getOffSessionReference(): string;

    /**
     * Metadata the gateway stores with the charge (string keys and values; Stripe shows them on the
     * PaymentIntent), e.g. ['sisub_subscription' => 'S000007', 'sisub_cycle' => '2'].
     *
     * @return array<string, string>
     */
    public function getOffSessionMetadata(): array;

    /** The gateway-side description of the charge ("Subscription S000007, renewal 2"), already translated. */
    public function getOffSessionDescription(): string;

    public function getCustomerId(): ?int;

    public function getStoreId(): int;

    /** The method to charge with: a vault code (braintree_cc_vault), a gateway method, an offline method. */
    public function getPaymentMethod(): string;

    /** vault_payment_token.entity_id for the Vault strategy, else null. */
    public function getVaultTokenId(): ?int;

    /**
     * Strategy-owned data: 'strategy' (the strategy code), 'checkout_method', gateway ids
     * (stripe_customer_id, mollie_customer_id, tokenbase_card_id...).
     *
     * @return array<string, mixed>
     */
    public function getPaymentData(): array;

    /** @return $this */
    public function setPaymentMethod(string $method);

    /** @return $this */
    public function setVaultTokenId(?int $tokenId);

    /**
     * @param array<string, mixed> $data
     * @return $this
     */
    public function setPaymentData(array $data);
}
