<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Observer\OffSession;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;
use SalesIgniter\Common\Model\Payment\OffSession\CartHandOff;

/**
 * sales_model_service_quote_submit_success: the order of a hand-off cart (CartHandOff) was placed, so
 * the cart the visitor had before is active again.
 */
class RestoreParkedCart implements ObserverInterface
{
    /** @var CartHandOff */
    private $handOff;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(CartHandOff $handOff, LoggerInterface $logger)
    {
        $this->handOff = $handOff;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $quote = $observer->getEvent()->getData('quote');
        if (!$quote instanceof CartInterface || !(int)$quote->getData(CartHandOff::RESTORE_FIELD)) {
            return;
        }
        try {
            $this->handOff->restore($quote);
        } catch (\Throwable $e) {
            // the order is placed; a cart that cannot come back must not undo that
            $this->logger->warning('[sicommon] parked cart not restored after quote ' . $quote->getId() . ': ' . $e->getMessage());
        }
    }
}
