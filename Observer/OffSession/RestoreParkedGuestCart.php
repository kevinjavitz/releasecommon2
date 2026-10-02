<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Observer\OffSession;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use SalesIgniter\Common\Model\Payment\OffSession\CartHandOff;

/**
 * checkout_onepage_controller_success_action: Magento's success page has just emptied the session's
 * cart; when the order paid a guest's hand-off cart (CartHandOff), the guest's own cart goes back
 * into the session.
 */
class RestoreParkedGuestCart implements ObserverInterface
{
    /** @var CartHandOff */
    private $handOff;

    public function __construct(CartHandOff $handOff)
    {
        $this->handOff = $handOff;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface || $order->getCustomerId() || !$order->getQuoteId()) {
            return;
        }
        try {
            $this->handOff->restoreToSession((int)$order->getQuoteId());
        } catch (\Throwable $e) {
            return; // never break the success page over a cart
        }
    }
}
