<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Stripe\Model;

use Magento\Framework\ObjectManagerInterface;
use StripeIntegration\Payments\Helper\Generic as StripeHelper;
use StripeIntegration\Payments\Model\Config as StripeConfig;

/**
 * The few Stripe API calls the off-session charges need, through the Stripe module's own configured client (its
 * keys, mode and API version for the store being charged). One class so tests can replace it.
 *
 * The Stripe module's Config and Generic helper are taken from the object manager by name, the same shared
 * instances constructor injection gave, so no Stripe type is in the constructor: setup:di:compile reads every
 * constructor under SubModules/*, also on stores without Stripe, where this sub-module is not registered
 * (Test/Unit/Architecture/SubModulesCompileSafeTest).
 */
class StripeGateway
{
    /** @var StripeConfig */
    private $config;
    /** @var StripeHelper */
    private $helper;

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->config = $objectManager->get(StripeConfig::class);
        $this->helper = $objectManager->get(StripeHelper::class);
    }

    /**
     * A merchant-initiated charge on a saved payment method: off_session + confirm, so Stripe flags it
     * as MIT and answers at once. A decline throws \Stripe\Exception\CardException.
     *
     * @param array<string, string> $metadata
     * @return \Stripe\PaymentIntent
     */
    public function chargeOffSession(
        string $customerId,
        string $paymentMethodId,
        float $amount,
        string $currency,
        string $description,
        array $metadata,
        string $idempotencyKey,
        $storeId = null
    ) {
        $client = $this->client($storeId);
        return $client->paymentIntents->create([
            'amount' => $this->helper->convertMagentoAmountToStripeAmount($amount, strtolower($currency)),
            'currency' => strtolower($currency),
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
            'off_session' => true,
            'confirm' => true,
            'description' => $description,
            'metadata' => $metadata,
        ], ['idempotency_key' => $idempotencyKey]);
    }

    /** @return \Stripe\PaymentIntent */
    public function retrievePaymentIntent(string $id, $storeId = null)
    {
        return $this->client($storeId)->paymentIntents->retrieve($id, []);
    }

    /** @param array<string, mixed> $params */
    public function updatePaymentIntent(string $id, array $params, $storeId = null): void
    {
        $this->client($storeId)->paymentIntents->update($id, $params);
    }

    /** @return \Stripe\PaymentMethod */
    public function retrievePaymentMethod(string $id, $storeId = null)
    {
        return $this->client($storeId)->paymentMethods->retrieve($id, []);
    }

    /** @return \Stripe\StripeClient */
    private function client($storeId)
    {
        $this->config->initStripe(null, $storeId);
        $client = $this->config->getStripeClient();
        if (!$client) {
            throw new \Magento\Framework\Exception\LocalizedException(__('Stripe is not configured for this store (API keys).'));
        }
        return $client;
    }
}
