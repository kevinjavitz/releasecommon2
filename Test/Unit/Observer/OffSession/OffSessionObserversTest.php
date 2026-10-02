<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Observer\OffSession;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Vault\Model\Ui\VaultConfigProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SalesIgniter\Common\Model\Payment\OffSession\CartHandOff;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirement;
use SalesIgniter\Common\Model\Payment\OffSession\SavedCardRequirementInterface;
use SalesIgniter\Common\Observer\OffSession\ForceVaultSave;
use SalesIgniter\Common\Observer\OffSession\RestoreParkedCart;
use SalesIgniter\Common\Observer\OffSession\RestoreParkedGuestCart;
use SalesIgniter\Common\Observer\OffSession\SuppressMitOrderEmail;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

class OffSessionObserversTest extends TestCase
{
    private function observer(array $data): Observer
    {
        return new Observer(['event' => new Event($data)]);
    }

    private function requirement(bool $required): SavedCardRequirement
    {
        $provider = $this->createStub(SavedCardRequirementInterface::class);
        $provider->method('requiresSavedCard')->willReturn($required);
        return new SavedCardRequirement([$provider]);
    }

    /** @return array{Order, Quote, OrderPayment, QuotePayment} */
    private function orderAndQuote(): array
    {
        $orderPayment = $this->getMockBuilder(OrderPayment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getPayment'])->getMock();
        $order->method('getPayment')->willReturn($orderPayment);
        $quotePayment = $this->getMockBuilder(QuotePayment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods(['getPayment'])->getMock();
        $quote->method('getPayment')->willReturn($quotePayment);
        return [$order, $quote, $orderPayment, $quotePayment];
    }

    public function testACheckoutThatNeedsTheCardKeepsItInTheVault(): void
    {
        [$order, $quote, $orderPayment, $quotePayment] = $this->orderAndQuote();
        (new ForceVaultSave($this->requirement(true), new MitContext()))->execute($this->observer(['order' => $order, 'quote' => $quote]));
        self::assertTrue($orderPayment->getAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE));
        self::assertTrue($quotePayment->getAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE));
    }

    public function testNothingIsForcedWhenNotRequiredOrDuringAnOffSessionCharge(): void
    {
        [$order, $quote, $orderPayment] = $this->orderAndQuote();
        (new ForceVaultSave($this->requirement(false), new MitContext()))->execute($this->observer(['order' => $order, 'quote' => $quote]));
        self::assertNull($orderPayment->getAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE));
        $mit = new MitContext();
        $mit->run(new Subject(), false, function () use ($mit, $order, $quote) {
            (new ForceVaultSave($this->requirement(true), $mit))->execute($this->observer(['order' => $order, 'quote' => $quote]));
        });
        self::assertNull($orderPayment->getAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE), 'the charge pays with the saved card');
    }

    public function testTheOrderEmailIsHeldOnlyWhenTheChargeAsksForIt(): void
    {
        $mit = new MitContext();
        $order = new DataObject();
        $observer = new SuppressMitOrderEmail($mit);
        $observer->execute($this->observer(['order' => $order]));
        self::assertNull($order->getData('can_send_new_email_flag'));
        $mit->run(new Subject(), false, fn() => $observer->execute($this->observer(['order' => $order])), [MitContext::SUPPRESS_ORDER_EMAIL => false]);
        self::assertNull($order->getData('can_send_new_email_flag'));
        $mit->run(new Subject(), false, fn() => $observer->execute($this->observer(['order' => $order])), [MitContext::SUPPRESS_ORDER_EMAIL => true]);
        self::assertFalse($order->getData('can_send_new_email_flag'));
    }

    public function testTheParkedCartComesBackAndAFailureNeverBreaksTheOrder(): void
    {
        $handOff = $this->createMock(CartHandOff::class);
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $handOff->expects(self::once())->method('restore')->with($quote)->willThrowException(new \RuntimeException('db'));
        $observer = new RestoreParkedCart($handOff, new NullLogger());
        $observer->execute($this->observer(['quote' => $quote]));       // not a hand-off cart: no call
        $quote->setData(CartHandOff::RESTORE_FIELD, 12);
        $observer->execute($this->observer(['quote' => $quote]));       // throws inside, swallowed
    }

    public function testAGuestsCartGoesBackOnTheSuccessPage(): void
    {
        $handOff = $this->createMock(CartHandOff::class);
        $handOff->expects(self::once())->method('restoreToSession')->with(31);
        $observer = new RestoreParkedGuestCart($handOff);
        $order = fn(array $d) => $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods([])->getMock()->setData($d);
        $observer->execute($this->observer(['order' => $order(['customer_id' => 4, 'quote_id' => 30])]));
        $observer->execute($this->observer(['order' => $order(['quote_id' => 31])]));
        $observer->execute($this->observer([]));
    }
}
