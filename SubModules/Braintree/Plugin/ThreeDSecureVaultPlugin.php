<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Braintree\Plugin;

use PayPal\Braintree\Gateway\Request\ThreeDSecureVaultDataBuilder;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;

/**
 * No "3DS required" on a merchant-initiated charge. From cron, request->isSecure() is false, so
 * Braintree's "verify 3D Secure" + threshold would demand 3DS on every vault sale over the
 * threshold, and a customer who is not there cannot answer it. A soft decline for authentication
 * still becomes action_required and a payment link.
 */
class ThreeDSecureVaultPlugin
{
    /** @var MitContext */
    private $context;

    public function __construct(MitContext $context)
    {
        $this->context = $context;
    }

    public function afterBuild(ThreeDSecureVaultDataBuilder $subject, array $result, array $buildSubject): array
    {
        if (!$this->context->isActive()) {
            return $result;
        }
        if (isset($result['options']['threeDSecure'])) {
            unset($result['options']['threeDSecure']);
            if (empty($result['options'])) {
                unset($result['options']);
            }
        }
        return $result;
    }
}
