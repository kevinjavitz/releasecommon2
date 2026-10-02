<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Quote\Api\Data\CartInterface;

/**
 * "This checkout must keep the card for a later off-session charge, whatever the customer's 'save
 * my card' choice." Each consumer answers for its own quotes (subscriptions: a quote with a
 * subscription line; rental: a part-paid booking collected automatically) and registers its provider
 * in the `providers` argument of SavedCardRequirement, the composite the forcing points ask:
 *
 *  - Observer\OffSession\ForceVaultSave: Magento Vault methods (is_active_payment_token_enabler);
 *  - SalesIgniter_CommonPayStripe: setup_future_usage = off_session;
 *  - SalesIgniter_CommonPayTokenBase: the TokenBase card is saved active.
 *
 * Never asked while MitContext is active (a merchant-initiated charge never saves a new card).
 */
interface SavedCardRequirementInterface
{
    public function requiresSavedCard(CartInterface $quote): bool;
}
