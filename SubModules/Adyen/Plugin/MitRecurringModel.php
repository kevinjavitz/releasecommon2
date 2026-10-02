<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Adyen\Plugin;

use Adyen\Payment\Gateway\Request\RecurringVaultDataBuilder;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;

/**
 * A charge on a stored Adyen token while MitContext is active is merchant-initiated: shopperInteraction
 * ContAuth (Adyen already sends it for *_vault methods) and recurringProcessingModel "Subscription"
 * for a fixed instalment, "UnscheduledCardOnFile" when the amount varies or the charge is a one-off
 * later charge (MitContext::isVariableAmount()), whatever the token was saved with.
 */
class MitRecurringModel
{
    /** @var MitContext */
    private $context;

    public function __construct(MitContext $context)
    {
        $this->context = $context;
    }

    /**
     * @param RecurringVaultDataBuilder $subject
     * @param array $result
     */
    public function afterBuild(RecurringVaultDataBuilder $subject, $result): array
    {
        $result = (array)$result;
        if (!$this->context->isActive()) {
            return $result;
        }
        $result['body']['recurringProcessingModel'] = $this->context->isVariableAmount() ? 'UnscheduledCardOnFile' : 'Subscription';
        $result['body']['shopperInteraction'] = 'ContAuth';
        return $result;
    }
}
