<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Observer\OffSession;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;

/**
 * sales_model_service_quote_submit_before: the order placed by a merchant-initiated charge sends no
 * new-order email when the caller asked for that (MitContext::run(..., [MitContext::SUPPRESS_ORDER_EMAIL
 * => true]); subscriptions: the "suppress the renewal order email" setting). The caller sends its own
 * email instead.
 */
class SuppressMitOrderEmail implements ObserverInterface
{
    /** @var MitContext */
    private $mit;

    public function __construct(MitContext $mit)
    {
        $this->mit = $mit;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        if ($order && $this->mit->suppressesOrderEmail()) {
            $order->setCanSendNewEmailFlag(false);
        }
    }
}
