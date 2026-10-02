<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\TokenBase\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\Model\Payment\OffSession\OffSessionMethods;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirement;
use SalesIgniter\Common\SubModules\TokenBase\Model\TokenBaseStrategy;

/**
 * sales_model_service_quote_submit_before: a checkout that must keep the card (SavedCardRequirement)
 * keeps it active in TokenBase whatever the "save this card" box said. TokenBase creates the card
 * while the order is placed (AbstractMethod::loadOrCreateCard -> Card::importPaymentInfo), reading
 * the payment's additional_information 'save' (1 = active); without it the later charge would have
 * no card. Moved from releasesubscriptions2's TokenBase sub-module.
 */
class SaveCardForOffSession implements ObserverInterface
{
    /** @var OffSessionMethods */
    private $methods;
    /** @var SavedCardRequirement */
    private $savedCards;
    /** @var MitContext */
    private $mit;

    public function __construct(OffSessionMethods $methods, SavedCardRequirement $savedCards, MitContext $mit)
    {
        $this->methods = $methods;
        $this->savedCards = $savedCards;
        $this->mit = $mit;
    }

    public function execute(Observer $observer)
    {
        $quote = $observer->getEvent()->getData('quote');
        $order = $observer->getEvent()->getData('order');
        if (!$quote instanceof Quote || !$order || $this->mit->isActive() || !$this->savedCards->requiresSavedCard($quote)) {
            return;
        }
        $method = (string)$order->getPayment()->getMethod();
        if ($this->methods->strategyFor($method, (int)$quote->getStoreId()) !== TokenBaseStrategy::CODE) {
            return;
        }
        $order->getPayment()->setAdditionalInformation('save', 1);
        $quote->getPayment()->setAdditionalInformation('save', 1);
    }
}
