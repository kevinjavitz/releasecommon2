<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession\Strategy;

use Magento\Framework\Exception\LocalizedException;
use Magento\InstantPurchase\PaymentMethodIntegration\IntegrationsManager;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Model\Ui\VaultConfigProvider;
use SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\OffSessionMethods;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface;

/**
 * The generic path: Magento_Vault. The subject stores the vault method code (braintree_cc_vault)
 * and vault_payment_token.entity_id; a charge sets the quote payment exactly as
 * Magento_InstantPurchase does, and the provider's vault_authorize / vault_sale command charges the
 * token.
 *
 * InstantPurchase's PaymentConfiguration::configurePayment() is NOT called: Adobe Payment Services
 * plugs it and reads the customer session, which cron does not have. Its three lines are repeated
 * here, plus the provider-specific additional information when the provider registers one
 * (Braintree's creates a payment method nonce from the token).
 */
class VaultStrategy implements StrategyInterface
{
    public const CODE = 'vault';

    /** vault codes that need their own sub-module (not safe on the generic path) */
    private const EXCLUDED = ['payment_services_paypal_vault'];

    /** @var OffSessionMethods */
    private $methods;

    /** @var PaymentTokenManagementInterface */
    private $tokenManagement;

    /** @var PaymentTokenRepositoryInterface */
    private $tokenRepository;

    /** @var IntegrationsManager|null */
    private $integrations;

    public function __construct(
        OffSessionMethods $methods,
        PaymentTokenManagementInterface $tokenManagement,
        PaymentTokenRepositoryInterface $tokenRepository,
        ?IntegrationsManager $integrations = null
    ) {
        $this->methods = $methods;
        $this->tokenManagement = $tokenManagement;
        $this->tokenRepository = $tokenRepository;
        $this->integrations = $integrations;
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function captureFromOrder(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
        $payment = $order->getPayment();
        $method = (string)$payment->getMethod();
        $storeId = (int)$order->getStoreId();
        $vaultCode = $this->methods->vaultCodeFor($method, $storeId);
        $token = $this->tokenFromOrder($order);
        $subject->setPaymentMethod($vaultCode ?? $method);
        $subject->setVaultTokenId($token !== null ? (int)$token->getEntityId() : null);
        $subject->setPaymentData([
            'strategy' => self::CODE,
            'checkout_method' => $method,
            'initial_payment_id' => $payment->getEntityId() ? (int)$payment->getEntityId() : null,
        ]);
    }

    public function readiness(ChargeSubjectInterface $subject, Quote $quote): ?array
    {
        $vaultCode = $subject->getPaymentMethod();
        if (in_array($vaultCode, self::EXCLUDED, true)) {
            return [Decline::CONFIG, (string)__('%1 cannot be charged off-session yet.', $vaultCode)];
        }
        $token = $this->token($subject);
        if ($token === null) {
            return [Decline::ACTION_REQUIRED, (string)__('No saved payment method. Please pay and save a card.')];
        }
        if (!$token->getIsActive()) {
            return [Decline::ACTION_REQUIRED, (string)__('The saved payment method was removed. Please choose another one.')];
        }
        if ((int)$token->getCustomerId() !== (int)$subject->getCustomerId()) {
            return [Decline::CONFIG, (string)__('The saved payment method belongs to another customer.')];
        }
        $expires = (string)$token->getExpiresAt();
        if ($expires !== '' && $expires < gmdate('Y-m-d H:i:s')) {
            return [Decline::HARD, (string)__('The saved card has expired.')];
        }
        if (!$this->methods->isVaultCode($vaultCode, $subject->getStoreId())) {
            return [Decline::CONFIG, (string)__('The payment method %1 is not available.', $vaultCode)];
        }
        return null;
    }

    public function configureQuote(ChargeSubjectInterface $subject, Quote $quote): void
    {
        $token = $this->token($subject);
        if ($token === null) {
            throw new LocalizedException(__('No saved payment method.'));
        }
        $payment = $quote->getPayment();
        $payment->setQuote($quote);
        $payment->importData(['method' => $subject->getPaymentMethod()]);
        $info = [
            PaymentTokenInterface::CUSTOMER_ID => $token->getCustomerId(),
            PaymentTokenInterface::PUBLIC_HASH => $token->getPublicHash(),
            VaultConfigProvider::IS_ACTIVE_CODE => true,
            self::PAYMENT_FLAG => 'true',
        ];
        $payment->setAdditionalInformation(array_merge($info, $this->providerInformation($token, (int)$quote->getStoreId())));
    }

    public function isAsync(ChargeSubjectInterface $subject): bool
    {
        return false;
    }

    public function afterOrderPlaced(ChargeSubjectInterface $subject, OrderInterface $order): void
    {
    }

    public function isManual(ChargeSubjectInterface $subject): bool
    {
        return false;
    }

    /**
     * The subject's token; resolved lazily from the first payment when the gateway vaulted the card
     * after checkout (Adyen tokens can arrive by webhook).
     */
    public function token(ChargeSubjectInterface $subject): ?PaymentTokenInterface
    {
        $id = $subject->getVaultTokenId();
        if ($id !== null) {
            try {
                return $this->tokenRepository->getById($id);
            } catch (\Throwable $e) {
                return null;
            }
        }
        $paymentId = (int)($subject->getPaymentData()['initial_payment_id'] ?? 0);
        if ($paymentId > 0) {
            $token = $this->tokenManagement->getByPaymentId($paymentId);
            if ($token !== null) {
                $subject->setVaultTokenId((int)$token->getEntityId());
                return $token;
            }
        }
        return null;
    }

    private function tokenFromOrder(OrderInterface $order): ?PaymentTokenInterface
    {
        $payment = $order->getPayment();
        $publicHash = (string)$payment->getAdditionalInformation(PaymentTokenInterface::PUBLIC_HASH);
        if ($publicHash !== '' && $order->getCustomerId()) {
            $token = $this->tokenManagement->getByPublicHash($publicHash, (int)$order->getCustomerId());
            if ($token !== null) {
                return $token;
            }
        }
        $extension = $payment->getExtensionAttributes();
        if ($extension !== null && method_exists($extension, 'getVaultPaymentToken')) {
            $token = $extension->getVaultPaymentToken();
            if ($token !== null && $token->getEntityId()) {
                return $token;
            }
        }
        return $payment->getEntityId() ? $this->tokenManagement->getByPaymentId((int)$payment->getEntityId()) : null;
    }

    /** Provider-specific additional information registered for InstantPurchase (Braintree: a nonce). */
    private function providerInformation(PaymentTokenInterface $token, int $storeId): array
    {
        if ($this->integrations === null) {
            return [];
        }
        try {
            return (array)$this->integrations->getByToken($token, $storeId)->getAdditionalInformation($token);
        } catch (LocalizedException $e) {
            return []; // no InstantPurchase integration: the vault command resolves the token itself
        }
    }
}
