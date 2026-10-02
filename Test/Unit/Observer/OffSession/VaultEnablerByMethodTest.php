<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Observer\OffSession;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Model\Ui\VaultConfigProvider;
use Magento\Vault\Observer\AfterPaymentSaveObserver;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirement;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirementInterface;
use SalesIgniter\Common\Observer\OffSession\ForceVaultSave;

/**
 * Plan §15 R2 spike: does forcing `is_active_payment_token_enabler` at
 * sales_model_service_quote_submit_before (ForceVaultSave) keep the card, per Vault method? The
 * observer runs before Order::place(), so whatever reads the flag while the payment is placed sees it.
 *
 *  - Braintree (braintree -> braintree_cc_vault; PayPal, Apple Pay, Google Pay, Venmo, ACH use the
 *    same builder): YES. VaultDataBuilder reads the flag from the order payment when the sale
 *    request is built and sends storeInVaultOnSuccess.
 *  - Payflow Pro (payflowpro -> payflowpro_cc_vault): YES. Transparent::authorize() always creates
 *    the token (PNREF of the zero-amount authorization); the flag decides is_visible in
 *    AfterPaymentSaveObserver, tested here for any method.
 *  - Payment Services (payment_services_paypal_hosted_fields -> payment_services_paypal_vault): NO
 *    with this observer. The vault intent goes to PayPal when the PayPal order is created
 *    (paymentservicespaypal/order/create, request param `vault`), before the order is placed;
 *    VaultDetailsHandler stores a token only when that response carries mp-transaction.vault. It
 *    needs a plugin on OrderService::create() (logged-in customers) and its own off-session
 *    sub-module (VaultStrategy excludes payment_services_paypal_vault): link collection until then.
 */
class VaultEnablerByMethodTest extends TestCase
{
    /** @return array{OrderPayment, Order} */
    private function forcedOrderPayment(string $method): array
    {
        $orderPayment = $this->getMockBuilder(OrderPayment::class)->disableOriginalConstructor()
            ->onlyMethods(['getMethod', 'getExtensionAttributes', 'getOrder', 'getEntityId'])->getMock();
        $orderPayment->method('getMethod')->willReturn($method);
        $orderPayment->method('getEntityId')->willReturn(91);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getPayment', 'getCustomerId', 'getStore'])->getMock();
        $order->method('getPayment')->willReturn($orderPayment);
        $order->method('getCustomerId')->willReturn(5);
        $order->method('getStore')->willReturn($store);
        $orderPayment->method('getOrder')->willReturn($order);
        $quotePayment = $this->getMockBuilder(QuotePayment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getPayment'])->getMock();
        $quote->method('getPayment')->willReturn($quotePayment);

        $provider = $this->createStub(SavedCardRequirementInterface::class);
        $provider->method('requiresSavedCard')->willReturn(true);
        (new ForceVaultSave(new SavedCardRequirement([$provider]), new MitContext()))
            ->execute(new Observer(['event' => new Event(['order' => $order, 'quote' => $quote])]));
        return [$orderPayment, $order];
    }

    public function testBraintreeAsksTheGatewayToVaultTheCard(): void
    {
        if (!class_exists(\PayPal\Braintree\Gateway\Request\VaultDataBuilder::class)) {
            self::markTestSkipped('paypal/module-braintree-core is not installed');
        }
        [$payment] = $this->forcedOrderPayment('braintree');
        $subject = $this->createStub(PaymentDataObjectInterface::class);
        $subject->method('getPayment')->willReturn($payment);
        $reader = new \PayPal\Braintree\Gateway\Helper\SubjectReader();
        self::assertSame(['options' => ['storeInVaultOnSuccess' => true]], (new \PayPal\Braintree\Gateway\Request\VaultDataBuilder($reader))->build(['payment' => $subject]));
        self::assertSame(['options' => ['storeInVaultOnSuccess' => true]], (new \PayPal\Braintree\Gateway\Request\PayPal\VaultDataBuilder($reader))->build(['payment' => $subject]));
    }

    public function testTheTokenAGatewayReturnsIsSavedVisibleForAnyVaultMethod(): void
    {
        [$payment] = $this->forcedOrderPayment('payflowpro');
        $token = $this->createMock(PaymentTokenInterface::class);
        $token->method('getGatewayToken')->willReturn('A10P0D2B7C32');
        $token->method('getEntityId')->willReturn(null);
        $token->expects(self::once())->method('setIsVisible')->with(true);
        $extension = $this->createStub(OrderPaymentExtensionInterface::class);
        $extension->method('getVaultPaymentToken')->willReturn($token);
        $payment->method('getExtensionAttributes')->willReturn($extension);
        $encryptor = $this->createStub(EncryptorInterface::class);
        $encryptor->method('getHash')->willReturn('hash');
        (new AfterPaymentSaveObserver($this->createStub(PaymentTokenManagementInterface::class), $encryptor))
            ->execute(new Observer(['payment' => $payment]));
    }

    public function testPaymentServicesStoresNoTokenFromTheFlagAlone(): void
    {
        $handler = \Magento\PaymentServicesPaypal\Gateway\Response\VaultDetailsHandler::class;
        if (!class_exists($handler)) {
            self::markTestSkipped('magento/module-payment-services-paypal is not installed');
        }
        [$payment] = $this->forcedOrderPayment('payment_services_paypal_hosted_fields');
        $extension = $this->createMock(OrderPaymentExtensionInterface::class);
        $extension->expects(self::never())->method('setVaultPaymentToken');
        $payment->method('getExtensionAttributes')->willReturn($extension);
        $subject = $this->createStub(PaymentDataObjectInterface::class);
        $subject->method('getPayment')->willReturn($payment);
        $reflection = new \ReflectionClass($handler);
        $instance = $reflection->newInstanceWithoutConstructor();
        // PayPal answered without a vault block: the order was created without the vault intent
        $instance->handle(['payment' => $subject], ['mp-transaction' => ['id' => 'TX1', 'status' => 'COMPLETED']]);
        self::assertTrue($payment->getAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE), 'the flag is set, yet no token');
    }
}
