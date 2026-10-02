<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Stripe\Plugin;

use Magento\Quote\Model\Quote;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirement;
use StripeIntegration\Payments\Model\Config;

/**
 * A cart that must keep the card for a later off-session charge (SavedCardRequirement: a
 * subscription, a part-paid booking collected automatically) saves it for off-session use
 * (setup_future_usage = off_session), whatever the "save card" setting says, so the later charge can
 * be merchant-initiated. A card that is already saved (null from the module) stays as it is.
 * Moved from releasesubscriptions2's SaveCardForSubscriptions.
 */
class SaveCardForOffSession
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

    /**
     * @param Config $subject
     * @param string|null $result
     * @param Quote|mixed $quote
     * @return string|null
     */
    public function afterGetSetupFutureUsage(Config $subject, $result, $quote)
    {
        if (!$quote instanceof Quote || $this->mit->isActive() || !$this->savedCards->requiresSavedCard($quote)) {
            return $result;
        }
        $token = (string)$quote->getPayment()->getAdditionalInformation('token');
        if ($result === null && strpos($token, 'pm_') === 0) {
            return null; // a card saved at Stripe already (the module answers null for those)
        }
        return 'off_session';
    }
}
