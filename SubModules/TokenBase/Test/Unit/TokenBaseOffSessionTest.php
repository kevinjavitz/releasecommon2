<?php
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\TokenBase\Test\Unit;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use ParadoxLabs\Authnetcim\Model\Gateway;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\Clock;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\Model\Payment\OffSession\OffSessionMethods;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirement;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirementInterface;
use SalesIgniter\Common\SubModules\TokenBase\Model\DeclineMapper;
use SalesIgniter\Common\SubModules\TokenBase\Model\TokenBaseStrategy;
use SalesIgniter\Common\SubModules\TokenBase\Observer\SaveCardForOffSession;
use SalesIgniter\Common\SubModules\TokenBase\Plugin\AuthnetcimMit;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

/**
 * The TokenBase sub-module (Authorize.Net CIM, CyberSource) without a gateway account. Moved from
 * the subscriptions TokenBase sub-module.
 */
#[AllowMockObjectsWithoutExpectations]
class TokenBaseOffSessionTest extends TestCase
{
    private function subject(array $paymentData = ['strategy' => 'tokenbase', 'tokenbase_card_id' => 12]): Subject
    {
        return new Subject(['customer_id' => 7, 'store_id' => 1, 'payment_method' => 'authnetcim', 'payment_data' => $paymentData]);
    }

    private function card(array $data): CardInterface
    {
        $card = $this->createStub(CardInterface::class);
        $card->method('getActive')->willReturn($data['active'] ?? 1);
        $card->method('getCustomerId')->willReturn($data['customer_id'] ?? 7);
        $card->method('getExpires')->willReturn($data['expires'] ?? '2031-01-31 23:59:59');
        $card->method('getHash')->willReturn('b0c5b7f9d0e1');
        return $card;
    }

    private function strategy(?CardInterface $card, bool $methodActive = true): TokenBaseStrategy
    {
        $cards = $this->createStub(CardRepositoryInterface::class);
        if ($card === null) {
            $cards->method('getById')->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException());
        } else {
            $cards->method('getById')->willReturn($card);
        }
        $method = $this->createStub(MethodInterface::class);
        $method->method('getConfigData')->willReturn($methodActive ? '1' : '0');
        $helper = $this->createStub(PaymentHelper::class);
        $helper->method('getMethodInstance')->willReturn($method);
        $time = $this->createStub(Clock::class);
        $time->method('nowString')->willReturn('2026-10-01 00:00:00');
        return new TokenBaseStrategy($cards, $helper, $time);
    }

    public function testTheFirstOrderRemembersTheCardAndMethod(): void
    {
        $payment = $this->getMockBuilder(OrderPayment::class)->disableOriginalConstructor()->onlyMethods(['getMethod', 'getExtensionAttributes'])->getMock();
        $payment->method('getMethod')->willReturn('authnetcim');
        $payment->setData('tokenbase_id', 44);
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getPayment'])->getMock();
        $order->method('getPayment')->willReturn($payment);
        $s = $this->subject([]);
        $this->strategy(null)->captureFromOrder($s, $order);
        self::assertSame('authnetcim', $s->getPaymentMethod());
        self::assertNull($s->getVaultTokenId(), 'not a Magento_Vault token');
        self::assertSame(['strategy' => 'tokenbase', 'checkout_method' => 'authnetcim', 'tokenbase_card_id' => 44], $s->getPaymentData());
    }

    public function testReadiness(): void
    {
        $quote = $this->createStub(Quote::class);
        self::assertNull($this->strategy($this->card([]))->readiness($this->subject(), $quote));
        self::assertSame(Decline::ACTION_REQUIRED, $this->strategy(null)->readiness($this->subject(), $quote)[0]);
        self::assertSame(Decline::ACTION_REQUIRED, $this->strategy($this->card(['active' => 0]))->readiness($this->subject(), $quote)[0], 'the customer deleted the card');
        self::assertSame(Decline::CONFIG, $this->strategy($this->card(['customer_id' => 9]))->readiness($this->subject(), $quote)[0]);
        self::assertSame(Decline::HARD, $this->strategy($this->card(['expires' => '2026-09-30 23:59:59']))->readiness($this->subject(), $quote)[0]);
        self::assertSame(Decline::CONFIG, $this->strategy($this->card([]), false)->readiness($this->subject(), $quote)[0], 'method switched off');
    }

    public function testAnOffSessionChargePaysWithTheCardHashAsASubscriptionCharge(): void
    {
        $payment = $this->getMockBuilder(QuotePayment::class)->disableOriginalConstructor()->onlyMethods(['importData', 'setQuote'])->getMock();
        $payment->expects($this->once())->method('importData')->with(['method' => 'authnetcim', 'card_id' => 'b0c5b7f9d0e1']);
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getPayment'])->getMock();
        $quote->method('getPayment')->willReturn($payment);
        $this->strategy($this->card([]))->configureQuote($this->subject(), $quote);
        self::assertSame(1, $payment->getAdditionalInformation('is_subscription_generated'), 'TokenBase\'s own "no CVV, recurring" flag');
    }

    public function testAuthorizeNetOffSessionChargesAreMerchantInitiated(): void
    {
        $context = new MitContext();
        $plugin = new AuthnetcimMit($context);
        $gateway = $this->getMockBuilder(Gateway::class)->disableOriginalConstructor()->onlyMethods(['setParameter'])->getMock();
        $calls = [];
        $gateway->method('setParameter')->willReturnCallback(function ($k, $v) use (&$calls, $gateway) {
            $calls[] = [$k, $v];
            return $gateway;
        });
        $plugin->beforeCreateTransaction($gateway);
        self::assertSame([], $calls, 'customer present: nothing changes');
        $context->run($this->subject(), false, fn() => $plugin->beforeCreateTransaction($gateway));
        self::assertSame([['isStoredCredentials', null], ['isSubsequentAuth', 'true']], $calls);
    }

    public function testTheAuthorizeNetAnswerIsSummarisedForTheClassifier(): void
    {
        $declined = [
            'messages' => ['resultCode' => 'Error', 'message' => [['code' => 'E00027', 'text' => 'The transaction was unsuccessful.']]],
            'transactionResponse' => ['responseCode' => '2', 'errors' => ['error' => ['errorCode' => '2', 'errorText' => 'This transaction has been declined.']]],
        ];
        $s = AuthnetcimMit::summarise($declined);
        self::assertSame(['gateway' => 'authnetcim', 'response_code' => '2', 'code' => '2', 'text' => 'This transaction has been declined.', 'api_code' => 'E00027'], $s);
        $expired = ['transactionResponse' => ['responseCode' => '3', 'errors' => [['errorCode' => '8', 'errorText' => 'The credit card has expired.']]]];
        self::assertSame('8', AuthnetcimMit::summarise($expired)['code']);
    }

    public static function declines(): array
    {
        $e = new \RuntimeException('Transaction failed');
        return [
            'declined' => [['gateway' => 'authnetcim', 'response_code' => '2', 'code' => '2'], $e, Decline::SOFT],
            'expired' => [['gateway' => 'authnetcim', 'response_code' => '3', 'code' => '8'], $e, Decline::HARD],
            'pick up card' => [['gateway' => 'authnetcim', 'response_code' => '2', 'code' => '4'], $e, Decline::HARD],
            'held for review' => [['gateway' => 'authnetcim', 'response_code' => '4', 'code' => '252'], $e, Decline::SOFT],
            'profile gone' => [['gateway' => 'authnetcim', 'api_code' => 'E00040'], $e, Decline::ACTION_REQUIRED],
            'cybersource funds' => [[], new \RuntimeException('Declined: INSUFFICIENT_FUND'), Decline::SOFT],
            'cybersource expired' => [[], new \RuntimeException('Declined: EXPIRED_CARD'), Decline::HARD],
            'cybersource 3DS' => [[], new \RuntimeException('CONSUMER_AUTHENTICATION_REQUIRED'), Decline::ACTION_REQUIRED],
        ];
    }

    #[DataProvider('declines')]
    public function testDeclineClasses(array $stash, \Throwable $error, string $class): void
    {
        self::assertSame($class, (new DeclineMapper())->map($stash, $error)->getClass());
    }

    public function testOtherErrorsAreLeftToTheGenericClassifier(): void
    {
        self::assertNull((new DeclineMapper())->map(['gateway' => 'adyen', 'code' => '8'], new \RuntimeException('x')));
    }

    public function testACheckoutThatNeedsTheCardSavesItInTokenBase(): void
    {
        $methods = $this->createStub(OffSessionMethods::class);
        $methods->method('strategyFor')->willReturnCallback(fn($code) => $code === 'authnetcim' ? 'tokenbase' : 'vault');
        $provider = $this->createStub(SavedCardRequirementInterface::class);
        $provider->method('requiresSavedCard')->willReturn(true);
        $observer = new SaveCardForOffSession($methods, new SavedCardRequirement([$provider]), new MitContext());
        foreach (['authnetcim' => 1, 'braintree' => null] as $method => $expected) {
            $orderPayment = $this->getMockBuilder(OrderPayment::class)->disableOriginalConstructor()->onlyMethods(['getMethod'])->getMock();
            $orderPayment->method('getMethod')->willReturn($method);
            $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getPayment'])->getMock();
            $order->method('getPayment')->willReturn($orderPayment);
            $quotePayment = $this->getMockBuilder(QuotePayment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
            $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getPayment', 'getStoreId'])->getMock();
            $quote->method('getPayment')->willReturn($quotePayment);
            $quote->method('getStoreId')->willReturn(1);
            $observer->execute(new Observer(['event' => new Event(['order' => $order, 'quote' => $quote])]));
            self::assertSame($expected, $orderPayment->getAdditionalInformation('save'), $method);
            self::assertSame($expected, $quotePayment->getAdditionalInformation('save'), $method);
        }
    }
}
