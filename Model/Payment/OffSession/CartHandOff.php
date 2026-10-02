<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;

/**
 * Hands a separate cart to the STANDARD checkout (a payment link: pay a subscription's unpaid cycle,
 * pay a booking balance) so every gateway's own 3DS, wallet and redirect flow works unchanged, and
 * gives the visitor's own cart back once that order is placed. Works for customers and guests.
 *
 *  1. park($handOff) before $handOff is saved: the visitor's current cart id goes into
 *     quote.sicommon_restore_quote_id of the hand-off cart. A customer's cart is deactivated (a
 *     customer has one active cart); a guest's stays active and is found again by its id.
 *  2. activate($handOff): it becomes the checkout session's cart. start() = park + save + activate.
 *  3. Once its order is placed (Observer\OffSession\RestoreParkedCart, quote submit success) the
 *     parked cart is active again; on the success page, after Magento empties the session's cart,
 *     a guest's parked cart goes back into the session (Observer\OffSession\RestoreParkedGuestCart).
 *
 * The consumer builds the hand-off cart's lines itself (subscriptions: the unpaid cycle at its
 * locked prices; rental: the balance line).
 */
class CartHandOff
{
    public const RESTORE_FIELD = 'sicommon_restore_quote_id';

    /** @var CartRepositoryInterface */
    private $quotes;
    /** @var CheckoutSession */
    private $checkoutSession;
    /** @var ResourceConnection */
    private $resource;

    public function __construct(CartRepositoryInterface $quotes, CheckoutSession $checkoutSession, ResourceConnection $resource)
    {
        $this->quotes = $quotes;
        $this->checkoutSession = $checkoutSession;
        $this->resource = $resource;
    }

    /**
     * Park the visitor's current cart on $handOff (call it before $handOff is saved). The visitor is
     * $handOff's customer, or the guest of this session when $handOff has no customer.
     *
     * @return int|null the parked cart's id
     */
    public function park(Quote $handOff): ?int
    {
        $customerId = (int)$handOff->getCustomerId();
        $previous = null;
        try {
            if ($customerId > 0) {
                $previous = $this->quotes->getActiveForCustomer($customerId);
            } elseif ((int)$this->checkoutSession->getQuoteId() > 0) {
                $previous = $this->quotes->getActive((int)$this->checkoutSession->getQuoteId());
            }
        } catch (NoSuchEntityException $e) {
            $previous = null;
        }
        if ($previous === null || ($handOff->getId() && (int)$previous->getId() === (int)$handOff->getId())) {
            $handOff->setData(self::RESTORE_FIELD, null);
            return null;
        }
        $handOff->setData(self::RESTORE_FIELD, (int)$previous->getId());
        if ($customerId > 0) {
            $previous->setIsActive(false);
            $this->quotes->save($previous);
        }
        return (int)$previous->getId();
    }

    /** Make $handOff the checkout session's cart (it must be saved and active). */
    public function activate(Quote $handOff): void
    {
        $this->checkoutSession->replaceQuote($handOff);
    }

    /** park(), save $handOff as an active cart, activate(). */
    public function start(Quote $handOff): Quote
    {
        $this->park($handOff);
        $handOff->setIsActive(true);
        $this->quotes->save($handOff);
        $this->activate($handOff);
        return $handOff;
    }

    /**
     * The order of hand-off cart $placed was placed: its parked cart is active again (when it still
     * has items).
     *
     * @return int|null the restored cart's id
     */
    public function restore(CartInterface $placed): ?int
    {
        $id = (int)$placed->getData(self::RESTORE_FIELD);
        return $id > 0 ? $this->reactivate($id) : null;
    }

    /** Make cart $quoteId active again when it still has items. @return int|null its id, or null */
    public function reactivate(int $quoteId): ?int
    {
        try {
            $previous = $this->quotes->get($quoteId);
        } catch (NoSuchEntityException $e) {
            return null; // the parked cart is gone; nothing to restore
        }
        if (!$previous->getItemsCount()) {
            return null;
        }
        if (!$previous->getIsActive()) {
            $previous->setIsActive(true);
            $this->quotes->save($previous);
        }
        return (int)$previous->getId();
    }

    /** The cart parked on hand-off cart $handOffQuoteId, read without loading the quote. */
    public function parkedCartId(int $handOffQuoteId): ?int
    {
        if ($handOffQuoteId <= 0) {
            return null;
        }
        $connection = $this->resource->getConnection('checkout');
        $id = (int)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('quote', 'checkout'), [self::RESTORE_FIELD])
                ->where('entity_id = ?', $handOffQuoteId)
        );
        return $id > 0 ? $id : null;
    }

    /**
     * On the success page, after Magento emptied the session's cart: when guest order's cart
     * $handOffQuoteId was a hand-off cart, the guest's parked cart becomes the session's cart again.
     * A customer's needs nothing (it is their active cart again).
     */
    public function restoreToSession(int $handOffQuoteId): void
    {
        $id = $this->parkedCartId($handOffQuoteId);
        if ($id === null) {
            return;
        }
        try {
            $previous = $this->quotes->getActive($id);
        } catch (NoSuchEntityException $e) {
            return;
        }
        if ($previous->getItemsCount() && !(int)$previous->getCustomerId()) {
            $this->checkoutSession->setQuoteId((int)$previous->getId());
        }
    }
}
