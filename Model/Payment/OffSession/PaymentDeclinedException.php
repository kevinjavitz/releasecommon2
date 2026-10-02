<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * An off-session charge could not be taken. Carries the classified Decline so the caller's policy
 * can choose between a retry, a payment link and stopping. DeclineClassifier returns it unchanged.
 */
class PaymentDeclinedException extends LocalizedException
{
    /** @var Decline */
    private $decline;

    public function __construct(Phrase $phrase, Decline $decline, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
        $this->decline = $decline;
    }

    public function getDecline(): Decline
    {
        return $this->decline;
    }
}
