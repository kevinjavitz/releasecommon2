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
use Magento\Vault\Model\Ui\VaultConfigProvider;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirement;

/**
 * sales_model_service_quote_submit_before: a checkout that must keep the card (SavedCardRequirement:
 * a subscription, a part-paid booking collected automatically) is placed with "save for later use"
 * on, whatever the customer ticked, so a Magento Vault method stores the token
 * (is_active_payment_token_enabler on the order's and the quote's payment; only Vault methods read
 * it). Moved from releasesubscriptions2's Observer\Quote\SubmitBefore. A merchant-initiated charge
 * (MitContext) is left alone: it pays with a token it already has.
 */
class ForceVaultSave implements ObserverInterface
{
    /** @var SavedCardRequirement */
    private $savedCards;
    /** @var MitContext */
    private $mit;

    public function __construct(SavedCardRequirement $savedCards, MitContext $mit)
    {
        $this->savedCards = $savedCards;
        $this->mit = $mit;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        $quote = $observer->getEvent()->getData('quote');
        if (!$order || !$quote instanceof CartInterface || $this->mit->isActive() || !$this->savedCards->requiresSavedCard($quote)) {
            return;
        }
        $order->getPayment()->setAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE, true);
        if ($quote->getPayment()) {
            $quote->getPayment()->setAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE, true);
        }
    }
}
