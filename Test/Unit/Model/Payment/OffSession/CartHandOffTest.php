<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\CartHandOff;

/**
 * A payment link's cart goes through the standard checkout and the visitor's own cart comes back:
 * a customer's (deactivated, then active again) and a guest's (left active, put back in the session).
 */
class CartHandOffTest extends TestCase
{
    /** @var array<int, Quote> */
    private $carts = [];
    /** @var int[] */
    private $saved = [];
    /** @var int|null */
    private $sessionQuoteId;
    /** @var int[] */
    private $sessionSetTo = [];
    /** @var int|false */
    private $parkedColumn = false;

    private function quote(array $data): Quote
    {
        $q = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $q->setData($data);
        if (isset($data['id'])) {
            $this->carts[(int)$data['id']] = $q;
        }
        return $q;
    }

    private function handOff(): CartHandOff
    {
        $quotes = $this->createStub(CartRepositoryInterface::class);
        $find = function ($id) {
            if (!isset($this->carts[(int)$id])) {
                throw new NoSuchEntityException(__('gone'));
            }
            return $this->carts[(int)$id];
        };
        $quotes->method('get')->willReturnCallback($find);
        $quotes->method('getActive')->willReturnCallback(function ($id) use ($find) {
            $q = $find($id);
            if (!$q->getIsActive()) {
                throw new NoSuchEntityException(__('inactive'));
            }
            return $q;
        });
        $quotes->method('getActiveForCustomer')->willReturnCallback(function ($customerId) {
            foreach ($this->carts as $q) {
                if ((int)$q->getCustomerId() === (int)$customerId && $q->getIsActive()) {
                    return $q;
                }
            }
            throw new NoSuchEntityException(__('none'));
        });
        $quotes->method('save')->willReturnCallback(function ($q) {
            $this->saved[] = (int)$q->getId();
        });
        $session = $this->getMockBuilder(CheckoutSession::class)->disableOriginalConstructor()
            ->onlyMethods(['replaceQuote', 'getQuoteId', 'setQuoteId'])->getMock();
        $session->method('getQuoteId')->willReturnCallback(fn() => $this->sessionQuoteId);
        $session->method('setQuoteId')->willReturnCallback(function ($id) use ($session) {
            $this->sessionSetTo[] = (int)$id;
            return $session;
        });
        $session->method('replaceQuote')->willReturnCallback(function ($q) use ($session) {
            $this->sessionSetTo[] = (int)$q->getId();
            return $session;
        });
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnCallback(fn() => $this->parkedColumn);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return new CartHandOff($quotes, $session, $resource);
    }

    public function testACustomersCartIsParkedInactiveAndComesBackWhenTheHandOffIsPlaced(): void
    {
        $own = $this->quote(['id' => 10, 'customer_id' => 5, 'is_active' => 1, 'items_count' => 2]);
        $service = $this->handOff();
        $handOff = $this->quote(['customer_id' => 5]);
        self::assertSame(10, $service->park($handOff));
        self::assertSame(10, $handOff->getData(CartHandOff::RESTORE_FIELD));
        self::assertFalse((bool)$own->getIsActive(), 'a customer has one active cart');
        self::assertSame([10], $this->saved);

        self::assertSame(10, $service->restore($handOff));
        self::assertTrue((bool)$own->getIsActive());
    }

    public function testAGuestsCartStaysActiveAndGoesBackIntoTheSessionOnTheSuccessPage(): void
    {
        $own = $this->quote(['id' => 20, 'is_active' => 1, 'items_count' => 1]);
        $this->sessionQuoteId = 20;
        $service = $this->handOff();
        $handOff = $this->quote(['id' => 21]);
        self::assertSame(20, $service->park($handOff));
        self::assertTrue((bool)$own->getIsActive());
        self::assertSame([], $this->saved, 'nothing to deactivate for a guest');

        $this->parkedColumn = '20';
        $service->restoreToSession(21);
        self::assertSame([20], $this->sessionSetTo);
    }

    public function testStartSavesTheHandOffActiveAndMakesItTheSessionCart(): void
    {
        $service = $this->handOff();
        $handOff = $this->quote(['id' => 30, 'customer_id' => 6]);
        self::assertSame($handOff, $service->start($handOff));
        self::assertNull($handOff->getData(CartHandOff::RESTORE_FIELD), 'no cart to park');
        self::assertTrue((bool)$handOff->getIsActive());
        self::assertSame([30], $this->saved);
        self::assertSame([30], $this->sessionSetTo);
    }

    public function testNothingComesBackWhenTheParkedCartIsGoneEmptyOrACustomers(): void
    {
        $service = $this->handOff();
        self::assertNull($service->restore($this->quote([CartHandOff::RESTORE_FIELD => 99])), 'gone');
        $this->quote(['id' => 40, 'is_active' => 0, 'items_count' => 0]);
        self::assertNull($service->reactivate(40), 'emptied meanwhile');
        self::assertSame([], $this->saved);

        $this->quote(['id' => 41, 'is_active' => 1, 'items_count' => 3, 'customer_id' => 8]);
        $this->parkedColumn = '41';
        $service->restoreToSession(42);
        $this->parkedColumn = false;
        $service->restoreToSession(43);
        self::assertSame([], $this->sessionSetTo, 'a customer cart is theirs already; no parked cart: nothing');
    }

    public function testTheHandOffCartItselfIsNeverParked(): void
    {
        $this->quote(['id' => 50, 'customer_id' => 9, 'is_active' => 1]);
        $service = $this->handOff();
        self::assertNull($service->park($this->carts[50]));
        self::assertSame([], $this->saved);
    }
}
