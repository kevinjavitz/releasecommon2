<?php
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Stripe\Test\Unit;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\Model\Payment\OffSession\PaymentDeclinedException;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirement;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirementInterface;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface;
use SalesIgniter\Common\Model\Payment\OffSession\SubjectSaverInterface;
use SalesIgniter\Common\SubModules\Stripe\Model\DeclineMapper;
use SalesIgniter\Common\SubModules\Stripe\Model\StripeGateway;
use SalesIgniter\Common\SubModules\Stripe\Model\StripeStrategy;
use SalesIgniter\Common\SubModules\Stripe\Plugin\SaveCardForOffSession;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use StripeIntegration\Payments\Model\Checkout\Flow;
use StripeIntegration\Payments\Model\Config as StripeConfig;

/**
 * The Stripe sub-module with a mocked Stripe client: what an off-session charge sends (the
 * subject's reference, description and metadata), how Stripe's answers are read, the double-charge
 * guard and the decline classes. Moved from the subscriptions Stripe sub-module.
 */
#[AllowMockObjectsWithoutExpectations]
class StripeOffSessionTest extends TestCase
{
    /** @var string[] references saved */
    private $saved = [];

    private function subject(array $paymentData): Subject
    {
        return new Subject([
            'reference' => 'sisub-7-2',
            'description' => 'Subscription S000007, renewal 2',
            'metadata' => ['sisub_subscription' => 'S000007', 'sisub_cycle' => 2],
            'payment_method' => 'stripe_payments',
            'payment_data' => $paymentData,
        ]);
    }

    private function quote(float $total): Quote
    {
        $payment = $this->getMockBuilder(QuotePayment::class)->disableOriginalConstructor()->onlyMethods(['importData', 'setQuote'])->getMock();
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()
            ->onlyMethods(['getPayment', 'collectTotals', 'getId'])->getMock();
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('collectTotals')->willReturnSelf();
        $quote->method('getId')->willReturn(55);
        $quote->setData(['grand_total' => $total, 'quote_currency_code' => 'USD']);
        return $quote;
    }

    private function strategy(StripeGateway $gateway, ?Flow $flow = null, bool $active = true, bool $savable = true): StripeStrategy
    {
        $config = $this->createStub(StripeConfig::class);
        $config->method('getConfigData')->willReturn($active ? '1' : '0');
        $saver = $this->createStub(SubjectSaverInterface::class);
        $saver->method('supports')->willReturn($savable);
        $saver->method('save')->willReturnCallback(function (ChargeSubjectInterface $s) {
            $this->saved[] = $s->getOffSessionReference();
        });
        return new StripeStrategy($gateway, $flow ?? new Flow(), $config, $saver, $this->createStub(LoggerInterface::class));
    }

    private function intent(array $values): PaymentIntent
    {
        return PaymentIntent::constructFrom($values + ['object' => 'payment_intent']);
    }

    public function testAnOffSessionChargeComesFirstThenTheOrderIsRecordedFromIt(): void
    {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->once())->method('chargeOffSession')
            ->with('cus_T1', 'pm_T1', 30.0, 'USD', 'Subscription S000007, renewal 2', ['sisub_subscription' => 'S000007', 'sisub_cycle' => '2'], 'sisub-7-2-55', 1)
            ->willReturn($this->intent(['id' => 'pi_OK', 'status' => 'succeeded', 'amount' => 3000]));
        $flow = new Flow();
        $s = $this->subject(['strategy' => 'stripe', 'stripe_customer_id' => 'cus_T1', 'stripe_payment_method' => 'pm_T1']);
        $quote = $this->quote(30.0);
        $this->strategy($gateway, $flow)->configureQuote($s, $quote);
        self::assertSame(['payment_intent' => 'pi_OK'], $flow->creatingOrderFromCharge, 'the Stripe module records this charge, no second charge');
        self::assertSame('pm_T1', $quote->getPayment()->getAdditionalInformation('token'));
        self::assertSame('true', $quote->getPayment()->getAdditionalInformation(StrategyInterface::PAYMENT_FLAG));
        self::assertSame(['reference' => 'sisub-7-2', 'id' => 'pi_OK', 'amount' => 3000], $s->getPaymentData()['stripe_pending']);
        self::assertSame(['sisub-7-2'], $this->saved, 'saved at once, before the order is placed');
    }

    public function testAChargeWhoseOrderFailedIsReusedForTheSameReference(): void
    {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->never())->method('chargeOffSession');
        $gateway->method('retrievePaymentIntent')->willReturn($this->intent(['id' => 'pi_PREV', 'status' => 'succeeded', 'amount' => 3000]));
        $flow = new Flow();
        $s = $this->subject(['stripe_customer_id' => 'cus_T1', 'stripe_payment_method' => 'pm_T1', 'stripe_pending' => ['reference' => 'sisub-7-2', 'id' => 'pi_PREV', 'amount' => 3000]]);
        $this->strategy($gateway, $flow)->configureQuote($s, $this->quote(30.0));
        self::assertSame(['payment_intent' => 'pi_PREV'], $flow->creatingOrderFromCharge);
    }

    public function testAPendingChargeOfAnotherReferenceIsNotReused(): void
    {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->never())->method('retrievePaymentIntent');
        $gateway->expects($this->once())->method('chargeOffSession')->willReturn($this->intent(['id' => 'pi_NEW', 'status' => 'succeeded', 'amount' => 3000]));
        $s = $this->subject(['stripe_customer_id' => 'cus_T1', 'stripe_payment_method' => 'pm_T1', 'stripe_pending' => ['reference' => 'sisub-7-1', 'id' => 'pi_OLD', 'amount' => 3000]]);
        $this->strategy($gateway)->configureQuote($s, $this->quote(30.0));
        self::assertSame('pi_NEW', $s->getPaymentData()['stripe_pending']['id']);
    }

    public function testAnIssuerAskingForTheCustomerIsActionRequired(): void
    {
        $gateway = $this->createStub(StripeGateway::class);
        $gateway->method('chargeOffSession')->willReturn($this->intent(['id' => 'pi_3DS', 'status' => 'requires_action', 'amount' => 3000]));
        try {
            $this->strategy($gateway)->configureQuote($this->subject(['stripe_customer_id' => 'cus_T1', 'stripe_payment_method' => 'pm_T1']), $this->quote(30.0));
            self::fail('no decline');
        } catch (PaymentDeclinedException $e) {
            self::assertSame(Decline::ACTION_REQUIRED, $e->getDecline()->getClass());
        }
    }

    public function testASubjectNothingCanSaveIsNeverCharged(): void
    {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->never())->method('chargeOffSession');
        $s = $this->subject(['stripe_customer_id' => 'cus_T1', 'stripe_payment_method' => 'pm_T1']);
        self::assertSame(Decline::CONFIG, $this->strategy($gateway, null, true, false)->readiness($s, $this->quote(1.0))[0]);
        $this->expectException(PaymentDeclinedException::class);
        $this->strategy($gateway, null, true, false)->configureQuote($s, $this->quote(30.0));
    }

    public function testTheCardIsLookedUpFromTheFirstPaymentAndReadinessChecksIt(): void
    {
        $gateway = $this->createStub(StripeGateway::class);
        $gateway->method('retrievePaymentIntent')->willReturn($this->intent(['id' => 'pi_FIRST', 'customer' => 'cus_A', 'payment_method' => 'pm_A', 'status' => 'succeeded']));
        $s = $this->subject(['strategy' => 'stripe', 'stripe_payment_intent' => 'pi_FIRST']);
        self::assertNull($this->strategy($gateway)->readiness($s, $this->quote(1.0)));
        self::assertSame('cus_A', $s->getPaymentData()['stripe_customer_id']);
        self::assertSame('pm_A', $s->getPaymentData()['stripe_payment_method']);
        self::assertSame(Decline::ACTION_REQUIRED, $this->strategy($this->createStub(StripeGateway::class))->readiness($this->subject([]), $this->quote(1.0))[0]);
        self::assertSame(Decline::CONFIG, $this->strategy($gateway, null, false)->readiness($this->subject(['stripe_customer_id' => 'c', 'stripe_payment_method' => 'p']), $this->quote(1.0))[0]);
    }

    public function testThePlacedOrderNumberGoesToStripeAndThePendingChargeIsCleared(): void
    {
        $gateway = $this->createMock(StripeGateway::class);
        $gateway->expects($this->once())->method('updatePaymentIntent')->with('pi_OK', $this->callback(
            fn($p) => ($p['metadata']['Order #'] ?? '') === '000000123' && $p['description'] === 'Order #000000123 (Subscription S000007, renewal 2)'
        ));
        $flow = new Flow();
        $flow->creatingOrderFromCharge = ['payment_intent' => 'pi_OK'];
        $s = $this->subject(['stripe_pending' => ['reference' => 'sisub-7-2', 'id' => 'pi_OK', 'amount' => 3000]]);
        $order = $this->getMockBuilder(\Magento\Sales\Model\Order::class)->disableOriginalConstructor()->onlyMethods(['getIncrementId'])->getMock();
        $order->method('getIncrementId')->willReturn('000000123');
        $this->strategy($gateway, $flow)->afterOrderPlaced($s, $order);
        self::assertNull($flow->creatingOrderFromCharge);
        self::assertArrayNotHasKey('stripe_pending', $s->getPaymentData());
    }

    public static function declines(): array
    {
        return [
            'insufficient funds' => ['card_declined', 'insufficient_funds', '', Decline::SOFT],
            'generic decline' => ['card_declined', 'generic_decline', '', Decline::SOFT],
            'expired' => ['expired_card', '', '', Decline::HARD],
            'lost card' => ['card_declined', 'lost_card', '', Decline::HARD],
            'do not try again' => ['card_declined', 'generic_decline', 'do_not_try_again', Decline::HARD],
            'SCA off session' => ['authentication_required', 'authentication_required', '', Decline::ACTION_REQUIRED],
        ];
    }

    #[DataProvider('declines')]
    public function testDeclineClasses(string $code, string $declineCode, string $advice, string $class): void
    {
        $body = ['error' => array_filter(['type' => 'card_error', 'code' => $code, 'decline_code' => $declineCode, 'advice_code' => $advice, 'message' => 'Your card was declined.'])];
        $e = CardException::factory('Your card was declined.', 402, json_encode($body), $body, null, $code, $declineCode ?: null);
        self::assertSame($class, (new DeclineMapper())->map([], $e)->getClass());
    }

    public function testOtherErrorsAreNotStripes(): void
    {
        self::assertNull((new DeclineMapper())->map([], new \RuntimeException('x')));
    }

    public function testACheckoutThatNeedsTheCardSavesItOffSession(): void
    {
        $provider = $this->createStub(SavedCardRequirementInterface::class);
        $provider->method('requiresSavedCard')->willReturn(true);
        $plugin = new SaveCardForOffSession(new SavedCardRequirement([$provider]), new MitContext());
        $config = $this->createStub(StripeConfig::class);
        $payment = $this->getMockBuilder(QuotePayment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getPayment'])->getMock();
        $quote->method('getPayment')->willReturn($payment);
        self::assertSame('off_session', $plugin->afterGetSetupFutureUsage($config, null, $quote));
        $payment->setAdditionalInformation('token', 'pm_SAVED');
        self::assertNull($plugin->afterGetSetupFutureUsage($config, null, $quote), 'a card saved at Stripe already');
        $mit = new MitContext();
        $during = new SaveCardForOffSession(new SavedCardRequirement([$provider]), $mit);
        $mit->run($this->subject([]), false, fn() => self::assertSame('on_session', $during->afterGetSetupFutureUsage($config, 'on_session', $quote)));
    }
}
