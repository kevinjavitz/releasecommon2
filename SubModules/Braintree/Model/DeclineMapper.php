<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Braintree\Model;

use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\DeclineMapperInterface;

/**
 * Braintree processor response codes and gateway rejections.
 */
class DeclineMapper implements DeclineMapperInterface
{
    private const HARD = ['2004', '2005', '2007', '2012', '2013', '2014', '2047', '2057'];
    private const SOFT = ['2000', '2001', '2002', '2003', '2038', '2044', '2046', '3000'];

    public function map(array $gatewayResult, \Throwable $error): ?Decline
    {
        if (($gatewayResult['gateway'] ?? '') !== 'braintree') {
            return null;
        }
        $code = (string)($gatewayResult['code'] ?? '');
        $message = trim((string)($gatewayResult['text'] ?? $gatewayResult['message'] ?? $error->getMessage()));
        $rejection = (string)($gatewayResult['gateway_rejection'] ?? '');
        if ($code === '2099' || $rejection === 'three_d_secure') {
            return new Decline(Decline::ACTION_REQUIRED, $code !== '' ? $code : $rejection, $message);
        }
        if ($rejection === 'application_incomplete' || stripos($message, 'authentication') !== false && $code === '') {
            return new Decline(Decline::CONFIG, $rejection !== '' ? $rejection : 'auth', $message);
        }
        if (in_array($code, self::HARD, true)) {
            return new Decline(Decline::HARD, $code, $message);
        }
        if (in_array($code, self::SOFT, true) || $rejection !== '') {
            return new Decline(Decline::SOFT, $code !== '' ? $code : $rejection, $message);
        }
        return null;
    }
}
