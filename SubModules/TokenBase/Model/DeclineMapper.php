<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\TokenBase\Model;

use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\DeclineMapperInterface;

/**
 * TokenBase gateways.
 *
 * Authorize.Net (stashed by AuthnetcimMit): response reason codes 2 / 3 declined (soft), 4 pick up
 * card, 6 / 37 invalid number, 7 / 8 expiry (hard), 252 / 253 held for review (soft); API error
 * E00040 "payment profile not found" means the member has to enter the card again.
 * CyberSource (message only): INSUFFICIENT_FUND / PROCESSOR_DECLINED soft, EXPIRED_CARD /
 * STOLEN_LOST_CARD / INVALID_ACCOUNT hard, CONSUMER_AUTHENTICATION_REQUIRED action.
 */
class DeclineMapper implements DeclineMapperInterface
{
    private const AUTHNET_HARD = ['4', '6', '7', '8', '37'];
    private const AUTHNET_SOFT = ['2', '3', '11', '19', '20', '21', '22', '23', '57', '65', '252', '253'];
    private const CYBERSOURCE = [
        'CONSUMER_AUTHENTICATION_REQUIRED' => Decline::ACTION_REQUIRED,
        'EXPIRED_CARD' => Decline::HARD,
        'STOLEN_LOST_CARD' => Decline::HARD,
        'INVALID_ACCOUNT' => Decline::HARD,
        'INSUFFICIENT_FUND' => Decline::SOFT,
        'PROCESSOR_DECLINED' => Decline::SOFT,
    ];

    public function map(array $gatewayResult, \Throwable $error): ?Decline
    {
        $message = $error->getMessage();
        if (($gatewayResult['gateway'] ?? '') === 'authnetcim') {
            $text = trim((string)($gatewayResult['text'] ?? '')) ?: $message;
            if (($gatewayResult['api_code'] ?? '') === 'E00040' || stripos($message, 'unable to find your payment record') !== false) {
                return new Decline(Decline::ACTION_REQUIRED, 'E00040', $text);
            }
            $code = (string)($gatewayResult['code'] ?? '');
            if (in_array($code, self::AUTHNET_HARD, true)) {
                return new Decline(Decline::HARD, $code, $text);
            }
            if (in_array($code, self::AUTHNET_SOFT, true) || ($gatewayResult['response_code'] ?? '') === '2') {
                return new Decline(Decline::SOFT, $code !== '' ? $code : 'declined', $text);
            }
            return null;
        }
        foreach (self::CYBERSOURCE as $reason => $class) {
            if (stripos($message, $reason) !== false) {
                return new Decline($class, $reason, $message);
            }
        }
        return null;
    }
}
