<?php
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Adyen\Test\Unit;

use Adyen\Payment\Gateway\Request\RecurringVaultDataBuilder;
use Adyen\Payment\Gateway\Validator\CheckoutResponseValidator;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\Model\Payment\OffSession\PaymentDeclinedException;
use SalesIgniter\Common\Model\Payment\OffSession\Strategy\VaultStrategy;
use SalesIgniter\Common\SubModules\Adyen\Model\AdyenStrategy;
use SalesIgniter\Common\SubModules\Adyen\Model\DeclineMapper;
use SalesIgniter\Common\SubModules\Adyen\Plugin\MitRecurringModel;
use SalesIgniter\Common\SubModules\Adyen\Plugin\StashResponse;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

/**
 * The Adyen sub-module without an Adyen account: what goes into an off-session /payments request,
 * how the answer is read, and the decline classes. Moved from the subscriptions Adyen sub-module
 * (the first-order "store the card as Subscription" plugins stay there).
 */
#[AllowMockObjectsWithoutExpectations]
class AdyenOffSessionTest extends TestCase
{
    protected function setUp(): void
    {
        // the sub-module is registered only where Adyen is installed; so are its tests
        if (!class_exists(CheckoutResponseValidator::class)) {
            $this->markTestSkipped('adyen/module-payment is not installed here');
        }
    }

    public function testAnOffSessionChargeIsContAuthWithTheRightProcessingModel(): void
    {
        $context = new MitContext();
        $plugin = new MitRecurringModel($context);
        $builder = $this->createStub(RecurringVaultDataBuilder::class);
        $body = ['body' => ['recurringProcessingModel' => 'CardOnFile', 'paymentMethod' => ['storedPaymentMethodId' => 'M5N7TQ4TG5PFWR50']]];
        self::assertSame($body, $plugin->afterBuild($builder, $body), 'customer present: unchanged');
        $fixed = $context->run(new Subject(), false, fn() => $plugin->afterBuild($builder, $body));
        self::assertSame('Subscription', $fixed['body']['recurringProcessingModel']);
        self::assertSame('ContAuth', $fixed['body']['shopperInteraction']);
        self::assertSame('M5N7TQ4TG5PFWR50', $fixed['body']['paymentMethod']['storedPaymentMethodId']);
        $variable = $context->run(new Subject(), true, fn() => $plugin->afterBuild($builder, $body));
        self::assertSame('UnscheduledCardOnFile', $variable['body']['recurringProcessingModel']);
    }

    public function testTheRefusalIsKeptForTheClassifier(): void
    {
        $context = new MitContext();
        $plugin = new StashResponse($context);
        $validator = $this->createStub(CheckoutResponseValidator::class);
        $context->run(new Subject(), false, function () use ($plugin, $validator) {
            $plugin->beforeValidate($validator, ['response' => [['resultCode' => 'Refused', 'refusalReason' => 'Expired Card', 'refusalReasonCode' => '6', 'pspReference' => 'X1']]]);
        });
        $stash = $context->gatewayResult();
        self::assertSame('adyen', $stash['gateway']);
        self::assertSame('6', $stash['code']);
        self::assertSame('Refused', $stash['result_code']);
        self::assertSame(Decline::HARD, (new DeclineMapper())->map($stash, new \RuntimeException('authError_refused'))->getClass());
    }

    public static function declines(): array
    {
        return [
            'refused' => [['gateway' => 'adyen', 'result_code' => 'Refused', 'code' => '2', 'text' => 'Refused'], Decline::SOFT, false],
            'not enough balance' => [['gateway' => 'adyen', 'result_code' => 'Refused', 'code' => '12', 'text' => 'Not enough balance'], Decline::SOFT, false],
            'expired' => [['gateway' => 'adyen', 'result_code' => 'Refused', 'code' => '6', 'text' => 'Expired Card'], Decline::HARD, false],
            'fraud' => [['gateway' => 'adyen', 'result_code' => 'Refused', 'code' => '20', 'text' => 'FRAUD'], Decline::HARD, false],
            'revocation of auth' => [['gateway' => 'adyen', 'result_code' => 'Refused', 'code' => '26', 'text' => 'Revocation Of Auth'], Decline::HARD, true],
            '3D not authenticated' => [['gateway' => 'adyen', 'result_code' => 'Refused', 'code' => '11', 'text' => '3D Not Authenticated'], Decline::ACTION_REQUIRED, false],
            'authentication required' => [['gateway' => 'adyen', 'result_code' => 'Refused', 'code' => '38', 'text' => 'Authentication required'], Decline::ACTION_REQUIRED, false],
            'challenge' => [['gateway' => 'adyen', 'result_code' => 'ChallengeShopper', 'code' => ''], Decline::ACTION_REQUIRED, false],
            'unknown refusal' => [['gateway' => 'adyen', 'result_code' => 'Refused', 'code' => '999'], Decline::SOFT, false],
        ];
    }

    #[DataProvider('declines')]
    public function testDeclineClasses(array $stash, string $class, bool $revoked): void
    {
        $decline = (new DeclineMapper())->map($stash, new \RuntimeException('authError_refused'));
        self::assertSame($class, $decline->getClass());
        self::assertSame($revoked, $decline->isRevoked());
    }

    public function testOtherGatewaysAreNotMapped(): void
    {
        self::assertNull((new DeclineMapper())->map(['gateway' => 'braintree', 'code' => '6'], new \RuntimeException('x')));
    }

    private function placedOrder(string $resultCode): Order
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getEntityId'])->getMock();
        $order->method('getEntityId')->willReturn(55);
        $order->setData('adyen_resulturl_event_code', $resultCode);
        return $order;
    }

    public function testAnIssuerThatWantsTheShopperCancelsTheOrder(): void
    {
        $orders = $this->createMock(OrderManagementInterface::class);
        $orders->expects($this->once())->method('cancel')->with(55);
        $strategy = new AdyenStrategy($this->createStub(VaultStrategy::class), $orders);
        try {
            $strategy->afterOrderPlaced(new Subject(), $this->placedOrder('RedirectShopper'));
            self::fail('no decline');
        } catch (PaymentDeclinedException $e) {
            self::assertSame(Decline::ACTION_REQUIRED, $e->getDecline()->getClass());
        }
    }

    public function testAuthorisedAndPendingWaitForTheWebhook(): void
    {
        $orders = $this->createMock(OrderManagementInterface::class);
        $orders->expects($this->never())->method('cancel');
        $strategy = new AdyenStrategy($this->createStub(VaultStrategy::class), $orders);
        $strategy->afterOrderPlaced(new Subject(), $this->placedOrder('Authorised'));
        $strategy->afterOrderPlaced(new Subject(), $this->placedOrder('Pending'));
        self::assertTrue($strategy->isAsync(new Subject()));
        self::assertSame('adyen', $strategy->getCode());
    }

    public function testTheSubjectRemembersTheAdyenStrategy(): void
    {
        $vault = $this->createMock(VaultStrategy::class);
        $vault->expects($this->once())->method('captureFromOrder')->willReturnCallback(
            fn(Subject $s) => $s->setPaymentData(['strategy' => 'vault', 'checkout_method' => 'adyen_cc'])
        );
        $s = new Subject();
        (new AdyenStrategy($vault, $this->createStub(OrderManagementInterface::class)))
            ->captureFromOrder($s, $this->createStub(\Magento\Sales\Api\Data\OrderInterface::class));
        self::assertSame('adyen', $s->getPaymentData()['strategy']);
        self::assertSame('adyen_cc', $s->getPaymentData()['checkout_method']);
    }
}
