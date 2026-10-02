<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Mollie\Model;

use Magento\Sales\Api\Data\OrderInterface;
use Mollie\Payment\Service\Order\TransactionPartInterface;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;

/**
 * The last part of Mollie's BuildTransaction pool: while MitContext is active, the transaction becomes
 * a merchant-initiated recurring payment on the customer's mandate (sequenceType=recurring, customerId,
 * the mandate's method, mandateId when known). Browser-only fields are dropped.
 */
class MitTransactionPart implements TransactionPartInterface
{
    /** @var MitContext */
    private $context;

    public function __construct(MitContext $context)
    {
        $this->context = $context;
    }

    public function process(OrderInterface $order, array $transaction): array
    {
        $subject = $this->context->subject();
        if ($subject === null) {
            return $transaction;
        }
        $data = $subject->getPaymentData();
        $transaction['sequenceType'] = 'recurring';
        if (!empty($data['mollie_customer_id'])) {
            $transaction['customerId'] = (string)$data['mollie_customer_id'];
        }
        if (!empty($data['mollie_mandate_id'])) {
            $transaction['mandateId'] = (string)$data['mollie_mandate_id'];
        }
        if (!empty($data['mollie_method'])) {
            $transaction['method'] = (string)$data['mollie_method'];
        }
        foreach (['cardToken', 'issuer', 'storeCredentials', 'applePayPaymentToken'] as $key) {
            unset($transaction[$key]);
            if (isset($transaction['payment']) && is_array($transaction['payment'])) {
                unset($transaction['payment'][$key]);
            }
        }
        if (isset($transaction['payment']) && is_array($transaction['payment'])) {
            $transaction['payment']['sequenceType'] = 'recurring';
            if (!empty($data['mollie_customer_id'])) {
                $transaction['payment']['customerId'] = (string)$data['mollie_customer_id'];
            }
            if (!empty($data['mollie_mandate_id'])) {
                $transaction['payment']['mandateId'] = (string)$data['mollie_mandate_id'];
            }
        }
        return $transaction;
    }
}
