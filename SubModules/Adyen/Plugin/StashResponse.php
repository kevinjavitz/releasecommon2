<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Adyen\Plugin;

use Adyen\Payment\Gateway\Validator\CheckoutResponseValidator;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;

/**
 * Keeps Adyen's raw answer to a merchant-initiated charge (resultCode, refusalReason, refusalReasonCode) for the
 * decline classifier: a refusal surfaces only as a translated "authError" message otherwise.
 */
class StashResponse
{
    /** @var MitContext */
    private $context;

    public function __construct(MitContext $context)
    {
        $this->context = $context;
    }

    /**
     * @param CheckoutResponseValidator $subject
     * @param array $validationSubject
     * @return null
     */
    public function beforeValidate(CheckoutResponseValidator $subject, array $validationSubject)
    {
        if (!$this->context->isActive()) {
            return null;
        }
        $responses = (array)($validationSubject['response'] ?? []);
        $response = $responses ? end($responses) : [];
        if (!is_array($response)) {
            return null;
        }
        $this->context->stashGatewayResult([
            'gateway' => 'adyen',
            'result_code' => (string)($response['resultCode'] ?? ''),
            'code' => (string)($response['refusalReasonCode'] ?? $response['additionalData']['refusalReasonCode'] ?? ''),
            'text' => (string)($response['refusalReason'] ?? $response['error'] ?? ''),
            'error_code' => (string)($response['errorCode'] ?? ''),
            'psp_reference' => (string)($response['pspReference'] ?? ''),
        ]);
        return null;
    }
}
