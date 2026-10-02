<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Vault\Api\PaymentMethodListInterface;
use Magento\Vault\Model\VaultPaymentInterface;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\OffSessionMethods;

/**
 * Moved from releasesubscriptions2 (OffSessionMethodsTest) with the class.
 */
class OffSessionMethodsTest extends TestCase
{
    private function methods(array $extra = []): OffSessionMethods
    {
        $vaults = [];
        foreach (['braintree' => 'braintree_cc_vault', 'braintree_paypal' => 'braintree_paypal_vault'] as $provider => $code) {
            $v = $this->createStub(VaultPaymentInterface::class);
            $v->method('getProviderCode')->willReturn($provider);
            $v->method('getCode')->willReturn($code);
            $vaults[] = $v;
        }
        $list = $this->createStub(PaymentMethodListInterface::class);
        $list->method('getList')->willReturn($vaults);
        $helper = $this->createStub(PaymentHelper::class);
        $helper->method('getPaymentMethodList')->willReturn(['braintree' => 'Credit Card (Braintree)', 'checkmo' => 'Check / Money order']);
        return new OffSessionMethods($list, $helper, ['checkmo', 'banktransfer'], $extra);
    }

    public function testACardPaidThroughAVaultProviderRenewsThroughItsVaultCode(): void
    {
        $m = $this->methods();
        $this->assertSame('braintree_cc_vault', $m->vaultCodeFor('braintree'));
        $this->assertSame('braintree_paypal_vault', $m->vaultCodeFor('braintree_paypal'));
        $this->assertSame(OffSessionMethods::STRATEGY_VAULT, $m->strategyFor('braintree'));
    }

    public function testPayingWithAnAlreadySavedCardIsTheVaultMethodItself(): void
    {
        $m = $this->methods();
        $this->assertSame('braintree_cc_vault', $m->vaultCodeFor('braintree_cc_vault'));
        $this->assertTrue($m->isVaultCode('braintree_cc_vault'));
        $this->assertFalse($m->isVaultCode('braintree'));
    }

    public function testOfflineFreeAndExtraMethodsHaveTheirOwnStrategies(): void
    {
        $m = $this->methods(['mollie_methods_creditcard' => 'mollie']);
        $this->assertSame(OffSessionMethods::STRATEGY_OFFLINE, $m->strategyFor('checkmo'));
        $this->assertSame(OffSessionMethods::STRATEGY_FREE, $m->strategyFor('free'));
        $this->assertSame('mollie', $m->strategyFor('mollie_methods_creditcard'));
        $this->assertNull($m->strategyFor('paypal_express'));
        $this->assertFalse($m->canCharge('paypal_express'));
        $this->assertTrue($m->canCharge('braintree'));
    }

    public function testTheSettingsListNamesEachMethodOnce(): void
    {
        $list = $this->methods()->checkoutMethods();
        $this->assertSame('Credit Card (Braintree)', $list['braintree']);
        $this->assertSame('braintree_paypal', $list['braintree_paypal'], 'no title falls back to the code');
        $this->assertArrayHasKey('free', $list);
        $this->assertSame(array_unique(array_keys($list)), array_keys($list));
    }

    public function testOneBrokenPaymentMethodDoesNotHideEveryVault(): void
    {
        // Magento's Vault list throws as a whole when one code has no model (Adyen 11.2 adyen_momo_wallet_vault)
        $list = $this->createStub(PaymentMethodListInterface::class);
        $list->method('getList')->willThrowException(new \UnexpectedValueException('Payment model name is not provided in config!'));
        $vault = $this->createStub(VaultPaymentInterface::class);
        $vault->method('getProviderCode')->willReturn('sisub_test');
        $vault->method('getCode')->willReturn('sisub_test_vault');
        $vault->method('isActive')->willReturn(true);
        $inactive = $this->createStub(VaultPaymentInterface::class);
        $inactive->method('getProviderCode')->willReturn('braintree');
        $inactive->method('getCode')->willReturn('braintree_cc_vault');
        $inactive->method('isActive')->willReturn(false);
        $helper = $this->createStub(PaymentHelper::class);
        $helper->method('getPaymentMethods')->willReturn(['checkmo' => [], 'adyen_momo_wallet_vault' => [], 'sisub_test_vault' => [], 'braintree_cc_vault' => []]);
        $helper->method('getMethodInstance')->willReturnCallback(function ($code) use ($vault, $inactive) {
            if ($code === 'adyen_momo_wallet_vault') {
                throw new \UnexpectedValueException('Payment model name is not provided in config!');
            }
            if ($code === 'sisub_test_vault') {
                return $vault;
            }
            if ($code === 'braintree_cc_vault') {
                return $inactive;
            }
            return $this->createStub(\Magento\Payment\Model\MethodInterface::class);
        });
        $m = new OffSessionMethods($list, $helper);
        $this->assertSame(['sisub_test' => 'sisub_test_vault'], $m->vaultCodesByProvider(1));
        $this->assertSame(OffSessionMethods::STRATEGY_VAULT, $m->strategyFor('sisub_test', 1));
    }
}
