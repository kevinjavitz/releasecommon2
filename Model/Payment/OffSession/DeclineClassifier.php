<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

/**
 * Classifies a failed off-session charge. Gateway mappers go first (they see raw codes, stashed in
 * MitContext by the gateway sub-modules); the generic rules below read the exception message, which
 * is all a gateway that re-throws a translated "Your payment could not be taken" leaves. Unknown
 * failures are soft: retrying is safer than giving up on a paying customer.
 */
class DeclineClassifier
{
    /** message fragments (lower case) per class, checked in this order */
    private const RULES = [
        Decline::ACTION_REQUIRED => [
            'authentication_required', 'authentication required', 'requires_action', 'three_d_secure',
            '3d secure', '3ds', 'challengeshopper', 'redirectshopper', 'identifyshopper', 'sca',
            'cardholder authentication', 'mfa',
        ],
        Decline::CONFIG => [
            'not available now', 'payment method is not available', 'is not available', 'api key', 'authentication failed',
            'invalid credentials', 'merchant account', 'application_incomplete', 'not configured', 'token not found',
            'no stored card', 'no saved card', 'the requested payment method is not available', 'not allowed for this store',
        ],
        Decline::HARD => [
            'expired card', 'card expired', 'expired_card', 'card_expired', 'invalid card', 'invalid_card', 'incorrect number',
            'incorrect_number', 'invalid account', 'invalid_account', 'no account', 'closed account', 'lost card', 'lost_card',
            'stolen', 'pick up card', 'pickup_card', 'restricted card', 'restricted_card', 'fraud', 'do_not_try_again',
            'revocation', 'revoked', 'inactive_card', 'hard decline', 'harddeclined', 'nonchargeable',
        ],
        Decline::SOFT => [
            'insufficient', 'do not honor', 'do_not_honor', 'limit exceeded', 'declined', 'try again', 'issuer unavailable',
            'processor network unavailable', 'processing error', 'soft decline', 'softdeclined', 'timeout', 'timed out',
        ],
    ];

    /** processor response codes shared by Braintree and card networks */
    private const CODES = [
        '2099' => Decline::ACTION_REQUIRED,
        '2004' => Decline::HARD, '2005' => Decline::HARD, '2007' => Decline::HARD, '2012' => Decline::HARD,
        '2013' => Decline::HARD, '2014' => Decline::HARD, '2047' => Decline::HARD, '2057' => Decline::HARD,
        '2000' => Decline::SOFT, '2001' => Decline::SOFT, '2002' => Decline::SOFT, '2003' => Decline::SOFT,
        '2038' => Decline::SOFT, '2044' => Decline::SOFT, '2046' => Decline::SOFT, '3000' => Decline::SOFT,
    ];

    /** @var DeclineMapperInterface[] */
    private $mappers;

    /** @var MitContext */
    private $context;

    public function __construct(MitContext $context, array $mappers = [])
    {
        $this->context = $context;
        $this->mappers = array_filter($mappers, static function ($m) {
            return $m instanceof DeclineMapperInterface;
        });
    }

    public function classify(\Throwable $error): Decline
    {
        if ($error instanceof PaymentDeclinedException) {
            return $error->getDecline();
        }
        $stash = $this->context->gatewayResult();
        foreach ($this->mappers as $mapper) {
            $decline = $mapper->map($stash, $error);
            if ($decline !== null) {
                return $decline;
            }
        }
        $message = $this->message($error);
        $code = isset($stash['code']) ? (string)$stash['code'] : $this->codeIn($message);
        if ($code !== '' && isset(self::CODES[$code])) {
            return new Decline(self::CODES[$code], $code, $message);
        }
        $lower = mb_strtolower($message);
        foreach (self::RULES as $class => $fragments) {
            foreach ($fragments as $fragment) {
                if ($this->contains($lower, $fragment)) {
                    $revoked = strpos($lower, 'revoc') !== false || strpos($lower, 'revoked') !== false;
                    return new Decline($class, $code !== '' ? $code : str_replace(' ', '_', $fragment), $message, $revoked);
                }
            }
        }
        return new Decline(Decline::SOFT, $code !== '' ? $code : 'unknown', $message);
    }

    private function message(\Throwable $error): string
    {
        $parts = [];
        for ($e = $error; $e !== null && count($parts) < 4; $e = $e->getPrevious()) {
            $m = trim($e->getMessage());
            if ($m !== '' && !in_array($m, $parts, true)) {
                $parts[] = $m;
            }
        }
        return implode(' / ', $parts) ?: get_class($error);
    }

    private function codeIn(string $message): string
    {
        return preg_match('/\b([23]0\d\d)\b/', $message, $m) ? $m[1] : '';
    }

    private function contains(string $haystack, string $needle): bool
    {
        if (strlen($needle) <= 4) {
            // short tokens (3ds, sca, mfa) only as whole words
            return (bool)preg_match('/\b' . preg_quote($needle, '/') . '\b/', $haystack);
        }
        return strpos($haystack, $needle) !== false;
    }
}
