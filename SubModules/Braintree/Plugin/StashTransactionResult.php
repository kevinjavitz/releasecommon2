<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Braintree\Plugin;

use Magento\Payment\Gateway\Http\TransferInterface;
use PayPal\Braintree\Gateway\Http\Client\TransactionSale;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;

/**
 * During a merchant-initiated charge, keep Braintree's raw result (processor response code, gateway
 * rejection reason, status) for the decline classifier: the module turns a decline into a generic
 * message.
 */
class StashTransactionResult
{
    /** @var MitContext */
    private $context;

    public function __construct(MitContext $context)
    {
        $this->context = $context;
    }

    public function afterPlaceRequest(TransactionSale $subject, array $response, TransferInterface $transfer): array
    {
        if (!$this->context->isActive()) {
            return $response;
        }
        $object = $response['object'] ?? null;
        $stash = ['gateway' => 'braintree'];
        if (is_object($object)) {
            $transaction = $object->transaction ?? null;
            if ($transaction !== null) {
                $stash['code'] = (string)($transaction->processorResponseCode ?? '');
                $stash['status'] = (string)($transaction->status ?? '');
                $stash['gateway_rejection'] = (string)($transaction->gatewayRejectionReason ?? '');
                $stash['text'] = (string)($transaction->processorResponseText ?? '');
            }
            if (isset($object->message)) {
                $stash['message'] = (string)$object->message;
            }
            $stash['success'] = (bool)($object->success ?? false);
        }
        $this->context->stashGatewayResult($stash);
        return $response;
    }
}
