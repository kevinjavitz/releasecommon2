<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Strategy;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\InstantPurchase\PaymentMethodIntegration\Integration;
use Magento\InstantPurchase\PaymentMethodIntegration\IntegrationsManager;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Model\Ui\VaultConfigProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\OffSessionMethods;
use SalesIgniter\Common\Model\Payment\OffSession\Strategy\FreeStrategy;
use SalesIgniter\Common\Model\Payment\OffSession\Strategy\OfflineStrategy;
use SalesIgniter\Common\Model\Payment\OffSession\Strategy\VaultStrategy;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

/**
 * The three core strategies: what they record from the first order, when they can charge, and how
 * they configure the off-session cart (always flagged with StrategyInterface::PAYMENT_FLAG).
 */
class StrategiesTest extends TestCase
{
    /** @var array<string, mixed> */
    private $info = [];
    /** @var array<string, mixed> */
    private $imported = [];

    private function quote(): Quote
    {
        $payment = $this->getMockBuilder(QuotePayment::class)->disableOriginalConstructor()
            ->onlyMethods(['setQuote', 'importData', 'setAdditionalInformation'])->getMock();
        $payment->method('setQuote')->willReturnSelf();
        $payment->method('importData')->willReturnCallback(function ($data) use ($payment) {
            $this->imported = $data;
            return $payment;
        });
        $payment->method('setAdditionalInformation')->willReturnCallback(function ($key, $value = null) use ($payment) {
            $this->info = is_array($key) ? $key : [$key => $value] + $this->info;
            return $payment;
        });
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getPayment', 'getStoreId'])->getMock();
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('getStoreId')->willReturn(1);
        return $quote;
    }

    private function token(array $data): PaymentTokenInterface
    {
        $data += ['entity_id' => 7, 'customer_id' => 3, 'is_active' => true, 'expires_at' => '2099-01-01 00:00:00', 'public_hash' => 'ph7'];
        $t = $this->createStub(PaymentTokenInterface::class);
        $t->method('getEntityId')->willReturn($data['entity_id']);
        $t->method('getCustomerId')->willReturn($data['customer_id']);
        $t->method('getIsActive')->willReturn($data['is_active']);
        $t->method('getExpiresAt')->willReturn($data['expires_at']);
        $t->method('getPublicHash')->willReturn($data['public_hash']);
        return $t;
    }

    private function vault(?PaymentTokenInterface $token, bool $isVaultCode = true, ?ObjectManagerInterface $objectManager = null): VaultStrategy
    {
        $methods = $this->createStub(OffSessionMethods::class);
        $methods->method('vaultCodeFor')->willReturnMap([['braintree', 1, 'braintree_cc_vault']]);
        $methods->method('isVaultCode')->willReturn($isVaultCode);
        $management = $this->createStub(PaymentTokenManagementInterface::class);
        $management->method('getByPublicHash')->willReturn($token);
        $management->method('getByPaymentId')->willReturn($token);
        $repository = $this->createStub(PaymentTokenRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(function () use ($token) {
            if ($token === null) {
                throw new \Magento\Framework\Exception\NoSuchEntityException(__('gone'));
            }
            return $token;
        });
        return new VaultStrategy($methods, $management, $repository, $objectManager ?? $this->createStub(ObjectManagerInterface::class));
    }

    private function order(string $method, array $info = [], ?string $po = null): Order
    {
        $payment = $this->getMockBuilder(OrderPayment::class)->disableOriginalConstructor()
            ->onlyMethods(['getMethod', 'getEntityId', 'getAdditionalInformation', 'getExtensionAttributes', 'getPoNumber'])->getMock();
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getEntityId')->willReturn(55);
        $payment->method('getAdditionalInformation')->willReturnCallback(fn($key = null) => $key === null ? $info : ($info[$key] ?? null));
        $payment->method('getExtensionAttributes')->willReturn(null);
        $payment->method('getPoNumber')->willReturn($po);
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()
            ->onlyMethods(['getPayment', 'getStoreId', 'getCustomerId'])->getMock();
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCustomerId')->willReturn(3);
        return $order;
    }

    public function testVaultRecordsTheVaultCodeAndTokenOfTheFirstOrder(): void
    {
        $subject = new Subject();
        $this->vault($this->token([]))->captureFromOrder($subject, $this->order('braintree', ['public_hash' => 'ph7']));
        self::assertSame('braintree_cc_vault', $subject->getPaymentMethod());
        self::assertSame(7, $subject->getVaultTokenId());
        self::assertSame(['strategy' => 'vault', 'checkout_method' => 'braintree', 'initial_payment_id' => 55], $subject->getPaymentData());
    }

    public function testVaultReadinessSortsEveryProblemIntoADeclineKind(): void
    {
        $subject = fn(array $d = []) => new Subject($d + ['payment_method' => 'braintree_cc_vault', 'vault_token_id' => 7]);
        $q = $this->quote();
        self::assertNull($this->vault($this->token([]))->readiness($subject(), $q));
        self::assertSame(Decline::ACTION_REQUIRED, $this->vault(null)->readiness($subject(), $q)[0], 'no token');
        self::assertSame(Decline::ACTION_REQUIRED, $this->vault($this->token(['is_active' => false]))->readiness($subject(), $q)[0], 'removed');
        self::assertSame(Decline::CONFIG, $this->vault($this->token(['customer_id' => 4]))->readiness($subject(), $q)[0], 'other customer');
        self::assertSame(Decline::HARD, $this->vault($this->token(['expires_at' => '2001-01-01 00:00:00']))->readiness($subject(), $q)[0], 'expired');
        self::assertSame(Decline::CONFIG, $this->vault($this->token([]), false)->readiness($subject(), $q)[0], 'vault disabled');
        self::assertSame(Decline::CONFIG, $this->vault($this->token([]))->readiness($subject(['payment_method' => 'payment_services_paypal_vault']), $q)[0], 'needs its own module');
    }

    public function testVaultConfiguresTheCartWithTheTokenAndTheFlags(): void
    {
        $this->vault($this->token([]))->configureQuote(new Subject(['payment_method' => 'braintree_cc_vault', 'vault_token_id' => 7]), $this->quote());
        self::assertSame(['method' => 'braintree_cc_vault'], $this->imported);
        self::assertSame('ph7', $this->info[PaymentTokenInterface::PUBLIC_HASH]);
        self::assertSame(3, $this->info[PaymentTokenInterface::CUSTOMER_ID]);
        self::assertTrue($this->info[VaultConfigProvider::IS_ACTIVE_CODE]);
        self::assertSame('true', $this->info[StrategyInterface::PAYMENT_FLAG]);
    }

    /**
     * Magento_InstantPurchase is optional, so VaultStrategy takes its IntegrationsManager from the object manager
     * by name, on the first charge (not when the strategy is built), once; the provider's additional information
     * (Braintree: a nonce) joins the payment as it did when di.xml injected the manager.
     */
    public function testVaultAddsTheInstantPurchaseProviderInformationResolvedOnFirstUse(): void
    {
        if (!class_exists(IntegrationsManager::class)) {
            self::markTestSkipped('Magento_InstantPurchase is not installed');
        }
        $token = $this->token([]);
        $integration = $this->createMock(Integration::class);
        $integration->method('getAdditionalInformation')->with($token)->willReturn(['payment_method_nonce' => 'nonce-1']);
        $manager = $this->createMock(IntegrationsManager::class);
        $manager->expects($this->exactly(2))->method('getByToken')->with($token, 1)->willReturn($integration);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->never())->method('create');
        $calls = 0;
        $objectManager->method('get')->willReturnCallback(function (string $class) use ($manager, &$calls) {
            $calls++;
            self::assertSame(IntegrationsManager::class, $class);
            return $manager;
        });
        $vault = $this->vault($token, true, $objectManager);
        self::assertSame(0, $calls, 'not resolved when the strategy is built');
        $subject = new Subject(['payment_method' => 'braintree_cc_vault', 'vault_token_id' => 7]);
        $vault->configureQuote($subject, $this->quote());
        $vault->configureQuote($subject, $this->quote());
        self::assertSame(1, $calls, 'resolved once');
        self::assertSame('nonce-1', $this->info['payment_method_nonce']);
        self::assertSame('ph7', $this->info[PaymentTokenInterface::PUBLIC_HASH]);
        self::assertSame('true', $this->info[StrategyInterface::PAYMENT_FLAG]);
    }

    public function testVaultWithoutAnInstantPurchaseIntegrationForTheTokenAddsNothing(): void
    {
        if (!class_exists(IntegrationsManager::class)) {
            self::markTestSkipped('Magento_InstantPurchase is not installed');
        }
        $manager = $this->createStub(IntegrationsManager::class);
        $manager->method('getByToken')->willThrowException(new LocalizedException(__('Instant purchase integration not available for token.')));
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturn($manager);
        $this->vault($this->token([]), true, $objectManager)
            ->configureQuote(new Subject(['payment_method' => 'braintree_cc_vault', 'vault_token_id' => 7]), $this->quote());
        self::assertEqualsCanonicalizing(
            [PaymentTokenInterface::CUSTOMER_ID, PaymentTokenInterface::PUBLIC_HASH, VaultConfigProvider::IS_ACTIVE_CODE, StrategyInterface::PAYMENT_FLAG],
            array_keys($this->info)
        );
    }

    public function testOfflineKeepsThePurchaseOrderNumberAndIsManual(): void
    {
        $helper = $this->createStub(PaymentHelper::class);
        $strategy = new OfflineStrategy($helper);
        $subject = new Subject();
        $strategy->captureFromOrder($subject, $this->order('purchaseorder', [], 'PO-9'));
        self::assertSame('purchaseorder', $subject->getPaymentMethod());
        self::assertSame(['strategy' => 'offline', 'po_number' => 'PO-9'], $subject->getPaymentData());
        self::assertTrue($strategy->isManual($subject));
        $strategy->configureQuote($subject, $this->quote());
        self::assertSame(['method' => 'purchaseorder', 'po_number' => 'PO-9'], $this->imported);
        self::assertSame('true', $this->info[StrategyInterface::PAYMENT_FLAG]);
    }

    public function testOfflineIsNotReadyWhenTheMethodIsDisabled(): void
    {
        $method = $this->createStub(MethodInterface::class);
        $method->method('isActive')->willReturn(false);
        $helper = $this->createStub(PaymentHelper::class);
        $helper->method('getMethodInstance')->willReturn($method);
        self::assertSame(Decline::CONFIG, (new OfflineStrategy($helper))->readiness(new Subject(['payment_method' => 'checkmo']), $this->quote())[0]);
    }

    public function testFreeChargesNothingWithTheFreeMethod(): void
    {
        $strategy = new FreeStrategy();
        $subject = new Subject();
        $strategy->captureFromOrder($subject, $this->order('free'));
        self::assertSame(['free', null, ['strategy' => 'free']], [$subject->getPaymentMethod(), $subject->getVaultTokenId(), $subject->getPaymentData()]);
        self::assertNull($strategy->readiness($subject, $this->quote()));
        $strategy->configureQuote($subject, $this->quote());
        self::assertSame(['method' => 'free'], $this->imported);
        self::assertSame('true', $this->info[StrategyInterface::PAYMENT_FLAG]);
    }
}
