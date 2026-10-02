<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Vault\Api\PaymentMethodListInterface;
use Magento\Vault\Model\VaultPaymentInterface;

/**
 * Which checkout payment methods can be charged again later without the customer (a renewal, a
 * booking balance), and through which strategy. Moved from releasesubscriptions2's RecurringMethods.
 *
 * - Every method with a Magento_Vault companion (braintree -> braintree_cc_vault, braintree_paypal ->
 *   braintree_paypal_vault, payflowpro -> payflowpro_cc_vault, and any third-party vault method):
 *   strategy "vault" (the generic path).
 * - The offline methods: strategy "offline" (the order is placed pending and paid by hand).
 * - free: strategy "free".
 * - Gateways with their own mechanism, added by a SalesIgniter_CommonPay* sub-module through the
 *   `extraMethods` argument (e.g. Mollie's mandate methods -> "mollie", stripe_payments -> "stripe").
 */
class OffSessionMethods
{
    public const STRATEGY_VAULT = 'vault';
    public const STRATEGY_OFFLINE = 'offline';
    public const STRATEGY_FREE = 'free';

    /** @var PaymentMethodListInterface */
    private $vaultMethods;

    /** @var PaymentHelper */
    private $paymentHelper;

    /** @var string[] */
    private $offlineMethods;

    /** @var array<string, string> checkout method code => strategy code */
    private $extraMethods;

    /** @var array<int, array<string, string>> store => provider => vault code */
    private $vaultByProvider = [];

    public function __construct(
        PaymentMethodListInterface $vaultMethods,
        PaymentHelper $paymentHelper,
        array $offlineMethods = ['checkmo', 'banktransfer', 'cashondelivery', 'purchaseorder'],
        array $extraMethods = []
    ) {
        $this->vaultMethods = $vaultMethods;
        $this->paymentHelper = $paymentHelper;
        $this->offlineMethods = array_values($offlineMethods);
        $this->extraMethods = array_filter($extraMethods, 'is_string');
    }

    /**
     * provider (checkout) code => vault code, for every active vault method this install has.
     *
     * Magento's own Vault list (PaymentMethodListInterface::getList()) throws as a whole when a
     * single payment code has no model in config (seen with Adyen 11.2: adyen_momo_wallet_vault), and
     * a store must not lose every saved card because of one broken third-party method.
     * So the list is tried first and, if it throws, the vault methods are found one by one, skipping
     * the methods that cannot be instantiated.
     *
     * @return array<string, string>
     */
    public function vaultCodesByProvider(?int $storeId = null): array
    {
        $key = (int)$storeId;
        if (!isset($this->vaultByProvider[$key])) {
            $map = [];
            try {
                foreach ($this->vaultMethods->getList($key) as $vault) {
                    $map[(string)$vault->getProviderCode()] = (string)$vault->getCode();
                }
            } catch (\Throwable $e) {
                $map = $this->scanVaultMethods($key);
            }
            $this->vaultByProvider[$key] = $map;
        }
        return $this->vaultByProvider[$key];
    }

    /** @return array<string, string> provider code => vault code, active vault methods only */
    private function scanVaultMethods(int $storeId): array
    {
        $map = [];
        try {
            $codes = array_keys((array)$this->paymentHelper->getPaymentMethods());
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($codes as $code) {
            try {
                $method = $this->paymentHelper->getMethodInstance((string)$code);
                if ($method instanceof VaultPaymentInterface && $method->isActive($storeId)) {
                    $map[(string)$method->getProviderCode()] = (string)$method->getCode();
                }
            } catch (\Throwable $e) {
                continue; // a method without a model, or a broken one: not a usable vault
            }
        }
        return $map;
    }

    public function vaultCodeFor(string $checkoutCode, ?int $storeId = null): ?string
    {
        $map = $this->vaultCodesByProvider($storeId);
        if (isset($map[$checkoutCode])) {
            return $map[$checkoutCode];
        }
        // paying with an already-saved card: the checkout method IS the vault method
        return in_array($checkoutCode, $map, true) ? $checkoutCode : null;
    }

    public function isVaultCode(string $code, ?int $storeId = null): bool
    {
        return in_array($code, $this->vaultCodesByProvider($storeId), true);
    }

    public function isOffline(string $code): bool
    {
        return in_array($code, $this->offlineMethods, true);
    }

    /**
     * The strategy that charges again a card first used with $checkoutCode; null when it cannot.
     */
    public function strategyFor(string $checkoutCode, ?int $storeId = null): ?string
    {
        if ($checkoutCode === 'free') {
            return self::STRATEGY_FREE;
        }
        if (isset($this->extraMethods[$checkoutCode])) {
            return $this->extraMethods[$checkoutCode];
        }
        if ($this->vaultCodeFor($checkoutCode, $storeId) !== null) {
            return self::STRATEGY_VAULT;
        }
        if ($this->isOffline($checkoutCode)) {
            return self::STRATEGY_OFFLINE;
        }
        return null;
    }

    /** true when a card first used with $checkoutCode can be charged again off-session */
    public function canCharge(string $checkoutCode, ?int $storeId = null): bool
    {
        return $this->strategyFor($checkoutCode, $storeId) !== null;
    }

    /**
     * Checkout methods that can be charged again later, code => admin title (for settings lists).
     *
     * @return array<string, string>
     */
    public function checkoutMethods(?int $storeId = null): array
    {
        $codes = array_merge(
            array_keys($this->vaultCodesByProvider($storeId)),
            $this->offlineMethods,
            array_keys($this->extraMethods),
            ['free']
        );
        $titles = [];
        try {
            $titles = $this->paymentHelper->getPaymentMethodList(true, false);
        } catch (\Throwable $e) {
            $titles = [];
        }
        $out = [];
        foreach (array_unique($codes) as $code) {
            $out[$code] = isset($titles[$code]) && $titles[$code] !== '' ? (string)$titles[$code] : $code;
        }
        return $out;
    }
}
