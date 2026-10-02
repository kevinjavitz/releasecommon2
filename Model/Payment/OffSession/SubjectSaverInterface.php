<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

/**
 * Saves a charge subject in the middle of a charge, when a strategy must not wait for the caller:
 * the Stripe strategy records a PaymentIntent that succeeded before the order exists, so a failed
 * order placement never leads to a second charge.
 *
 * Each consumer registers one for its own subjects in the `savers` argument of SubjectSaver (the
 * composite the strategies use): subscriptions for Subscription, rental for its balance rows.
 */
interface SubjectSaverInterface
{
    public function supports(ChargeSubjectInterface $subject): bool;

    /** Persist $subject (its payment method, vault token id and payment data at least). */
    public function save(ChargeSubjectInterface $subject): void;
}
