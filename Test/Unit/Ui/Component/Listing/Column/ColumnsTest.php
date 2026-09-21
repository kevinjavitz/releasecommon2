<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Ui\Component\Listing\Column;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order as SalesOrder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Ui\Component\Listing\Column\Order;
use SalesIgniter\Common\Ui\Component\Listing\Column\Orderincrement;
use SalesIgniter\Common\Ui\Component\Listing\Column\Product;
use SalesIgniter\Common\Ui\Component\Listing\Column\Type;

/**
 * The four admin grid columns. Three of them are referenced by class name from six
 * ui_component XML files in releaserental2 and releasemaintenance2:
 *
 *   Column\Order          rental_send_listing, rental_send_history_listing,
 *                         rental_return_history_listing, rental_returns_listing
 *   Column\Product        rental_send_history_listing, rental_return_history_listing,
 *                         maintenance_automated_listing
 *   Column\Orderincrement reservations_orders_listing
 *   Column\Type           not referenced by any ui_component today
 *
 * A grid column that throws takes the whole grid to a 500 with no useful message, so the
 * interesting cases are the missing rows: a deleted order, a deleted product, a zero id.
 */
class ColumnsTest extends TestCase
{
    /* ------------------------------------------------------------ Column\Order */

    public function testOrderRendersALinkToTheOrderUsingItsIncrementId(): void
    {
        $column = $this->orderColumn(['5' => ['increment_id' => '100000042', 'entity_id' => 5]]);

        $result = $column->prepareDataSource($this->dataSource([['order_id' => 5]]));
        $item = $result['data']['items'][0];

        $this->assertStringContainsString('sales/order/view/order_id/5', $item['order_id']);
        $this->assertStringContainsString('>100000042<', $item['order_id']);
    }

    public function testOrderShowsAHintToRegenerateWhenTheOrderIsGone(): void
    {
        $column = $this->orderColumn([]); // repository throws for everything

        $result = $column->prepareDataSource($this->dataSource([['order_id' => 5]]));

        $this->assertStringContainsString('deleted order(run regenerateInventory)', $result['data']['items'][0]['order_id']);
        $this->assertStringContainsString('order_id/0', $result['data']['items'][0]['order_id']);
    }

    public function testOrderLeavesAZeroIdAsAPlainZeroRatherThanALinkToNothing(): void
    {
        $column = $this->orderColumn([]);

        $result = $column->prepareDataSource($this->dataSource([['order_id' => 0]]));

        $this->assertSame('0', $result['data']['items'][0]['order_id']);
    }

    /**
     * Every one of these columns writes a `<name>backup` key beside the one it rewrote.
     * The comment in the source says it is so another column can still read the id - but it
     * is written AFTER the overwrite, so what lands in `order_idbackup` is the rendered
     * anchor, not the id. Pinned as-is: three grids ship this today.
     */
    public function testOrderBackupKeyHoldsTheRenderedHtmlNotTheOriginalId(): void
    {
        $column = $this->orderColumn(['5' => ['increment_id' => '100000042', 'entity_id' => 5]]);

        $result = $column->prepareDataSource($this->dataSource([['order_id' => 5]]));
        $item = $result['data']['items'][0];

        $this->assertArrayHasKey('order_idbackup', $item);
        $this->assertSame($item['order_id'], $item['order_idbackup']);
        $this->assertStringContainsString('<a href', $item['order_idbackup']);
    }

    public function testOrderPassesThroughADataSourceWithNoItems(): void
    {
        $column = $this->orderColumn([]);
        $source = ['data' => []];

        $this->assertSame($source, $column->prepareDataSource($source));
    }

    /* --------------------------------------------------- Column\Orderincrement */

    public function testOrderincrementLinksUsingTheRowsOwnOrderIdAndIncrementId(): void
    {
        $column = $this->orderincrementColumn();

        $result = $column->prepareDataSource($this->dataSource([
            ['increment_id' => '100000042', 'order_id' => 7],
        ], 'increment_id'));
        $item = $result['data']['items'][0];

        $this->assertStringContainsString('sales/order/view/order_id/7', $item['increment_id']);
        $this->assertStringContainsString('>100000042<', $item['increment_id']);
    }

    /**
     * It never touches the order repository - the columns it needs are already joined into
     * the grid collection. That is the difference from Column\Order and the reason the
     * reservations grid is not N+1.
     */
    public function testOrderincrementNeverAsksTheOrderRepositoryForAnything(): void
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->expects($this->never())->method('get');
        $repository->expects($this->never())->method('getList');

        $column = $this->orderincrementColumn($repository);
        $column->prepareDataSource($this->dataSource([
            ['increment_id' => '100000042', 'order_id' => 7],
        ], 'increment_id'));
    }

    public function testOrderincrementLeavesAZeroAsAZero(): void
    {
        $column = $this->orderincrementColumn();

        $result = $column->prepareDataSource($this->dataSource([
            ['increment_id' => 0, 'order_id' => 7],
        ], 'increment_id'));

        $this->assertSame('0', $result['data']['items'][0]['increment_id']);
    }

    /* ---------------------------------------------------------- Column\Product */

    public function testProductShowsTheProductName(): void
    {
        $column = $this->productColumn([99 => 'Two-person kayak']);

        $result = $column->prepareDataSource($this->dataSource([
            ['product_id' => 99],
        ], 'product_name'));

        $this->assertSame('Two-person kayak', $result['data']['items'][0]['product_name']);
    }

    public function testProductSaysProductDeletedRatherThanThrowingTheGridAway(): void
    {
        $column = $this->productColumn([]);

        $result = $column->prepareDataSource($this->dataSource([
            ['product_id' => 99],
        ], 'product_name'));

        $this->assertSame('Product Deleted', (string)$result['data']['items'][0]['product_name']);
    }

    /**
     * Column\Product catches NoSuchEntityException only, where Column\Order catches
     * \Exception. So a repository that fails for any other reason - a TypeError from a bad
     * id, say - still takes the grid down here. Pinned because the two columns sit in the
     * same two grids and behave differently.
     */
    public function testProductDoesNotSwallowErrorsOtherThanNoSuchEntity(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('getById')->willThrowException(new \RuntimeException('connection lost'));

        $column = $this->productColumn([], $repository);

        $this->expectException(\RuntimeException::class);
        $column->prepareDataSource($this->dataSource([['product_id' => 99]], 'product_name'));
    }

    public function testProductCastsTheIdSoAStringIdStillResolves(): void
    {
        $seen = [];
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(function ($id) use (&$seen) {
            $seen[] = $id;
            $product = $this->createMock(ProductInterface::class);
            $product->method('getName')->willReturn('Kayak');
            return $product;
        });

        $column = $this->productColumn([], $repository);
        $column->prepareDataSource($this->dataSource([['product_id' => '99']], 'product_name'));

        $this->assertSame([99], $seen);
    }

    /* ------------------------------------------------------------- Column\Type */

    /**
     */
    #[DataProvider('periodTypes')]
    public function testTypeRendersThePeriodName(int $stored, string $expected): void
    {
        $column = $this->typeColumn();

        $result = $column->prepareDataSource($this->dataSource([['price_type' => $stored]], 'price_type'));

        $this->assertSame($expected, (string)$result['data']['items'][0]['price_type']);
    }

    public static function periodTypes(): array
    {
        return [
            'daily' => [1, 'Daily'],
            'weekly' => [2, 'Weekly'],
            'monthly' => [3, 'Monthly'],
            'yearly' => [4, 'Yearly'],
            'never' => [5, 'Never'],
        ];
    }

    /**
     * Column\Type's switch has no default, so an id it does not know is left untouched
     * rather than blanked. TypeDayToYear only ever offers 1-4, so a stored 5 ("Never") can
     * only have come from somewhere else - and 0 or null come from rows written before the
     * column existed.
     */
    public function testTypeLeavesAnUnknownPeriodIdAlone(): void
    {
        $column = $this->typeColumn();

        $result = $column->prepareDataSource($this->dataSource([['price_type' => 99]], 'price_type'));

        $this->assertSame(99, $result['data']['items'][0]['price_type']);
    }

    /**
     * The column and the source model have to agree, and they are in different files with no
     * shared constant between them: TypeDayToYear offers 1-4 as Day/Week/Month/Year and the
     * column renders 1-4 as Daily/Weekly/Monthly/Yearly. Nothing but this test connects them.
     */
    public function testTypeAgreesWithTheSourceModelForOneToFour(): void
    {
        $column = $this->typeColumn();
        $source = new \SalesIgniter\Common\Model\Source\TypeDayToYear(
            $this->createMock(\Magento\Eav\Model\ResourceModel\Entity\Attribute\OptionFactory::class)
        );
        $expected = ['Day' => 'Daily', 'Week' => 'Weekly', 'Month' => 'Monthly', 'Year' => 'Yearly'];

        foreach ($source->getAllOptions() as $option) {
            $rendered = $column->prepareDataSource(
                $this->dataSource([['price_type' => $option['value']]], 'price_type')
            );

            $this->assertSame(
                $expected[(string)$option['label']],
                (string)$rendered['data']['items'][0]['price_type'],
                'source model value ' . $option['value'] . ' and the grid column disagree'
            );
        }
    }

    /* ---------------------------------------------------------------- helpers */

    private function dataSource(array $items, string $fieldName = 'order_id'): array
    {
        return ['data' => ['items' => $items], 'fieldName' => $fieldName];
    }

    private function orderColumn(array $ordersById): Order
    {
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function ($id) use ($ordersById) {
            if (!isset($ordersById[(string)$id])) {
                throw new NoSuchEntityException(__('No such entity.'));
            }
            // The column calls $order->getId(), which is on the model, not on OrderInterface -
            // so the repository contract alone is not enough to satisfy this column.
            $order = $this->createMock(SalesOrder::class);
            $order->method('getIncrementId')->willReturn($ordersById[(string)$id]['increment_id']);
            $order->method('getId')->willReturn($ordersById[(string)$id]['entity_id']);
            return $order;
        });

        $column = new Order(
            $this->context(),
            $this->createMock(UiComponentFactory::class),
            $this->createMock(Escaper::class),
            $repository,
            $this->urlBuilder()
        );
        $column->setData('name', 'order_id');

        return $column;
    }

    private function orderincrementColumn(?OrderRepositoryInterface $repository = null): Orderincrement
    {
        $column = new Orderincrement(
            $this->context(),
            $this->createMock(UiComponentFactory::class),
            $this->createMock(Escaper::class),
            $this->createMock(SearchCriteriaBuilder::class),
            $repository ?? $this->createMock(OrderRepositoryInterface::class),
            $this->urlBuilder()
        );
        $column->setData('name', 'increment_id');

        return $column;
    }

    private function productColumn(array $namesById, ?ProductRepositoryInterface $repository = null): Product
    {
        if ($repository === null) {
            $repository = $this->createMock(ProductRepositoryInterface::class);
            $repository->method('getById')->willReturnCallback(function ($id) use ($namesById) {
                if (!isset($namesById[$id])) {
                    throw new NoSuchEntityException(__('No such entity.'));
                }
                $product = $this->createMock(ProductInterface::class);
                $product->method('getName')->willReturn($namesById[$id]);
                return $product;
            });
        }

        $column = new Product(
            $this->context(),
            $this->createMock(UiComponentFactory::class),
            $this->createMock(Escaper::class),
            $repository
        );
        $column->setData('name', 'product_name');

        return $column;
    }

    private function typeColumn(): Type
    {
        $column = new Type(
            $this->context(),
            $this->createMock(UiComponentFactory::class),
            $this->createMock(PriceCurrencyInterface::class)
        );
        $column->setData('name', 'price_type');

        return $column;
    }

    private function context(): ContextInterface
    {
        $context = $this->createMock(ContextInterface::class);
        $context->method('getNamespace')->willReturn('test_listing');
        $context->method('getProcessor')->willReturn(
            $this->createMock(\Magento\Framework\View\Element\UiComponent\Processor::class)
        );

        return $context;
    }

    private function urlBuilder(): UrlInterface
    {
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static function (string $route, array $params = []): string {
                $path = $route;
                foreach ($params as $key => $value) {
                    $path .= '/' . $key . '/' . $value;
                }
                return 'https://mage.ddev.site/backend/' . $path;
            }
        );

        return $url;
    }
}
