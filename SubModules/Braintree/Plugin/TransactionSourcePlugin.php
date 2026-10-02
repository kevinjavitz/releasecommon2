<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Braintree\Plugin;

use PayPal\Braintree\Gateway\Request\TransactionSourceDataBuilder;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;

/**
 * Braintree's transactionSource on a merchant-initiated charge (the module sends only "moto", and
 * only in adminhtml): "recurring" for a fixed instalment, "unscheduled" when the amount varies or the
 * charge is a one-off later charge (MitContext::isVariableAmount(); a booking balance).
 *
 * The first order of a series ("recurring_first") is the consumer's to mark: the subscriptions
 * module's Braintree sub-module does it for an order that starts a subscription.
 */
class TransactionSourcePlugin
{
    /** @var MitContext */
    private $context;

    public function __construct(MitContext $context)
    {
        $this->context = $context;
    }

    public function afterBuild(TransactionSourceDataBuilder $subject, array $result, array $buildSubject): array
    {
        if ($this->context->isActive()) {
            $result[TransactionSourceDataBuilder::TRANSACTION_SOURCE] = $this->context->isVariableAmount() ? 'unscheduled' : 'recurring';
        }
        return $result;
    }
}
