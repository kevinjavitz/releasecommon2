<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Stripe\Model;

use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\DeclineMapperInterface;

/**
 * Stripe decline codes. Reads the \Stripe\Exception\CardException the
 * off-session charge throws: decline_code (or the error code) and advice_code; "do_not_try_again"
 * makes any decline hard. Revocations end the mandate.
 */
class DeclineMapper implements DeclineMapperInterface
{
    private const ACTION = ['authentication_required', 'authentication_not_handled', 'card_not_supported_off_session', 'requires_action'];
    private const HARD = [
        'expired_card', 'incorrect_number', 'invalid_number', 'invalid_account', 'lost_card', 'stolen_card', 'pickup_card',
        'restricted_card', 'revocation_of_authorization', 'revocation_of_all_authorizations', 'stop_payment_order',
        'fraudulent', 'card_not_supported', 'invalid_expiry_month', 'invalid_expiry_year', 'account_closed',
    ];
    private const SOFT = [
        'insufficient_funds', 'do_not_honor', 'generic_decline', 'issuer_not_available', 'processing_error',
        'try_again_later', 'card_velocity_exceeded', 'withdrawal_count_limit_exceeded', 'approve_with_id', 'reenter_transaction',
        'call_issuer', 'card_declined',
    ];
    private const CONFIG = ['api_key_expired', 'authentication_error', 'invalid_request_error', 'resource_missing'];

    public function map(array $gatewayResult, \Throwable $error): ?Decline
    {
        if (!$error instanceof \Stripe\Exception\ApiErrorException) {
            return null;
        }
        $body = $error->getJsonBody();
        $err = is_array($body['error'] ?? null) ? $body['error'] : [];
        $decline = (string)($err['decline_code'] ?? '');
        $code = (string)($err['code'] ?? $error->getStripeCode() ?? '');
        $advice = (string)($err['advice_code'] ?? '');
        $message = (string)($err['message'] ?? $error->getMessage());
        $key = $decline !== '' ? $decline : $code;
        if (in_array($key, self::ACTION, true) || in_array($code, self::ACTION, true)
            || ($err['payment_intent']['status'] ?? '') === 'requires_action') {
            return new Decline(Decline::ACTION_REQUIRED, $key !== '' ? $key : 'authentication_required', $message);
        }
        $revoked = in_array($key, ['revocation_of_authorization', 'revocation_of_all_authorizations', 'stop_payment_order'], true);
        if ($advice === 'do_not_try_again' || in_array($key, self::HARD, true)) {
            return new Decline(Decline::HARD, $key !== '' ? $key : $advice, $message, $revoked);
        }
        if (in_array($key, self::SOFT, true) || $error instanceof \Stripe\Exception\CardException) {
            return new Decline(Decline::SOFT, $key !== '' ? $key : 'card_declined', $message);
        }
        if (in_array($code, self::CONFIG, true) || $error instanceof \Stripe\Exception\AuthenticationException) {
            return new Decline(Decline::CONFIG, $code !== '' ? $code : 'stripe_api', $message);
        }
        return null;
    }
}
