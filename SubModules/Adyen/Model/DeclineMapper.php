<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Adyen\Model;

use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\DeclineMapperInterface;

/**
 * Adyen refusal reason codes (from Adyen's "Refusal reasons" page). 26 "Revocation of auth" also ends the mandate, like a revoked card.
 */
class DeclineMapper implements DeclineMapperInterface
{
    private const HARD = ['5', '6', '8', '14', '20', '25', '26', '31'];
    private const SOFT = ['2', '4', '9', '12', '27', '28', '29', '46'];
    private const ACTION = ['11', '38', '42'];

    public function map(array $gatewayResult, \Throwable $error): ?Decline
    {
        if (($gatewayResult['gateway'] ?? '') !== 'adyen') {
            return null;
        }
        $code = (string)($gatewayResult['code'] ?? '');
        $message = trim((string)($gatewayResult['text'] ?? '')) ?: $error->getMessage();
        $result = (string)($gatewayResult['result_code'] ?? '');
        if (in_array($result, ['RedirectShopper', 'ChallengeShopper', 'IdentifyShopper'], true) || in_array($code, self::ACTION, true)) {
            return new Decline(Decline::ACTION_REQUIRED, $code !== '' ? $code : $result, $message);
        }
        if (in_array($code, self::HARD, true)) {
            return new Decline(Decline::HARD, $code, $message, $code === '26');
        }
        if (in_array($code, self::SOFT, true)) {
            return new Decline(Decline::SOFT, $code, $message);
        }
        if ($result === 'Refused' || $result === 'Error') {
            return new Decline(Decline::SOFT, $code !== '' ? $code : strtolower($result), $message);
        }
        return null;
    }
}
