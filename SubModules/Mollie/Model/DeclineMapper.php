<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Mollie\Model;

use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\DeclineMapperInterface;

/**
 * Mollie failure reasons; SEPA reason codes too.
 */
class DeclineMapper implements DeclineMapperInterface
{
    private const MAP = [
        'authentication_required' => Decline::ACTION_REQUIRED,
        'authentication_failed' => Decline::ACTION_REQUIRED,
        'card_expired' => Decline::HARD,
        'inactive_card' => Decline::HARD,
        'invalid_card_number' => Decline::HARD,
        'possible_fraud' => Decline::HARD,
        'insufficient_funds' => Decline::SOFT,
        'card_declined' => Decline::SOFT,
        'refused_by_issuer' => Decline::SOFT,
        'unknown_reason' => Decline::SOFT,
        'AC04' => Decline::HARD,
        'MD01' => Decline::HARD,
        'AM04' => Decline::SOFT,
    ];

    public function map(array $gatewayResult, \Throwable $error): ?Decline
    {
        $message = $error->getMessage();
        foreach (self::MAP as $code => $class) {
            if (($gatewayResult['failure_reason'] ?? '') === $code || stripos($message, $code) !== false) {
                return new Decline($class, $code, $message, $code === 'MD01');
            }
        }
        if (stripos($message, 'mandate') !== false) {
            return new Decline(Decline::ACTION_REQUIRED, 'mandate', $message);
        }
        return null;
    }
}
