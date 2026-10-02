<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;

/**
 * "Visa ending in 4242 (expires 08/2027)", "PayPal (buyer@example.com)", or the method title, for
 * the pages, emails and GraphQL of whatever keeps a card on file (subscriptions, booking balances).
 * Reads vault_payment_token.details, never card data.
 */
class PaymentLabel
{
    private const CARD_TYPES = [
        'VI' => 'Visa', 'MC' => 'Mastercard', 'AE' => 'American Express', 'DI' => 'Discover', 'JCB' => 'JCB',
        'DN' => 'Diners Club', 'UN' => 'UnionPay', 'MI' => 'Maestro', 'visa' => 'Visa', 'mastercard' => 'Mastercard',
        'amex' => 'American Express', 'discover' => 'Discover',
    ];

    /** @var PaymentTokenRepositoryInterface */
    private $tokens;

    /** @var PaymentHelper */
    private $paymentHelper;

    public function __construct(PaymentTokenRepositoryInterface $tokens, PaymentHelper $paymentHelper)
    {
        $this->tokens = $tokens;
        $this->paymentHelper = $paymentHelper;
    }

    public function forSubject(ChargeSubjectInterface $subject): string
    {
        $tokenId = $subject->getVaultTokenId();
        if ($tokenId !== null) {
            try {
                return $this->forToken($this->tokens->getById($tokenId));
            } catch (\Throwable $e) {
                // token deleted: fall through to the method title
            }
        }
        return $this->methodTitle($subject->getPaymentMethod());
    }

    public function forToken(PaymentTokenInterface $token): string
    {
        $details = json_decode((string)$token->getTokenDetails(), true) ?: [];
        if ($token->getType() === 'card' || isset($details['maskedCC'])) {
            $type = (string)($details['type'] ?? $details['cc_type'] ?? '');
            $brand = self::CARD_TYPES[$type] ?? ($type !== '' ? $type : (string)__('Card'));
            $last4 = (string)($details['maskedCC'] ?? $details['cc_last_4'] ?? '');
            $label = $last4 !== '' ? (string)__('%1 ending in %2', $brand, $last4) : $brand;
            $expiry = (string)($details['expirationDate'] ?? '');
            return $expiry !== '' ? (string)__('%1 (expires %2)', $label, $expiry) : $label;
        }
        if (isset($details['payerEmail'])) {
            return (string)__('PayPal (%1)', (string)$details['payerEmail']);
        }
        return $this->methodTitle((string)$token->getPaymentMethodCode());
    }

    public function methodTitle(string $code): string
    {
        if ($code === '') {
            return (string)__('No payment method');
        }
        try {
            $title = (string)$this->paymentHelper->getMethodInstance($code)->getTitle();
            return $title !== '' ? $title : $code;
        } catch (\Throwable $e) {
            return $code;
        }
    }

    /** true when the token expires before $utc (card expiring notice) */
    public function expiresBefore(PaymentTokenInterface $token, string $utc): bool
    {
        $expires = (string)$token->getExpiresAt();
        return $expires !== '' && $expires < $utc;
    }
}
