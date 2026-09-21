<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Source;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\OptionFactory;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\Search\FilterGroupBuilder;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResults;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Source\Product;
use SalesIgniter\Common\Model\Source\TypeDayToYear;

/**
 * The two source models releasemaintenance2 injects into its three admin forms
 * (Ticket, Template and Automated Edit/Form.php all take both). A signature or shape change
 * here breaks those forms and nothing in this module would notice.
 */
class SourceModelsTest extends TestCase
{
    /* ------------------------------------------------------------ Source\Product */

    public function testItOffersEveryRentalProductAsAnOption(): void
    {
        $source = $this->productSource([
            ['id' => 12, 'name' => 'Kayak'],
            ['id' => 34, 'name' => 'Paddle board'],
        ]);

        $this->assertSame(
            [
                ['value' => '0', 'label' => 'None'],
                ['value' => 12, 'label' => 'Kayak'],
                ['value' => 34, 'label' => 'Paddle board'],
            ],
            $this->normalise($source->getAllOptions())
        );
    }

    public function testItAsksTheRepositoryForTypeIdEqualsSirentAndNothingElse(): void
    {
        $captured = [];
        $source = $this->productSource([], $captured);

        $source->getAllOptions();

        $this->assertSame(['field' => 'type_id', 'condition' => 'eq', 'value' => 'sirent'], $captured);
    }

    public function testWithNoRentalProductsTheOnlyOptionIsNone(): void
    {
        $source = $this->productSource([]);

        $this->assertSame(
            [['value' => '0', 'label' => 'None']],
            $this->normalise($source->getAllOptions())
        );
    }

    public function testWithoutEmptyItReturnsOnlyTheProducts(): void
    {
        $source = $this->productSource([['id' => 12, 'name' => 'Kayak']]);

        $this->assertSame(
            [['value' => 12, 'label' => 'Kayak']],
            $this->normalise($source->getAllOptions(false))
        );
    }

    /**
     * Documented behaviour, not a wish: with no products and $withEmpty = false the method
     * returns null, because $this->_options was never assigned. Callers that foreach over it
     * get a TypeError on PHP 8. Both callers in releasemaintenance2 use the default
     * ($withEmpty = true), which is why nobody has hit this.
     */
    public function testWithoutEmptyAndWithoutProductsItReturnsNullRatherThanAnEmptyArray(): void
    {
        $source = $this->productSource([]);

        $this->assertNull($source->getAllOptions(false));
    }

    public function testTheProductListIsFetchedOnceAndThenCached(): void
    {
        $calls = 0;
        $source = $this->productSource([['id' => 12, 'name' => 'Kayak']], $ignored, $calls);

        $source->getAllOptions();
        $source->getAllOptions();

        $this->assertSame(1, $calls, 'the second call must not hit the product repository again');
    }

    /* -------------------------------------------------------- Source\TypeDayToYear */

    public function testThePeriodTypesAreTheFourTheGridColumnRenders(): void
    {
        $source = new TypeDayToYear($this->createMock(OptionFactory::class));

        $this->assertSame(
            [
                ['value' => 1, 'label' => 'Day'],
                ['value' => 2, 'label' => 'Week'],
                ['value' => 3, 'label' => 'Month'],
                ['value' => 4, 'label' => 'Year'],
            ],
            $this->normalise($source->getAllOptions())
        );
    }

    /**
     * A bug, pinned rather than fixed.
     *
     * getAllOptions() appends to $this->_options instead of assigning, and never checks
     * whether it has already run. Call it twice on the same instance and you get eight
     * options: Day, Week, Month, Year, Day, Week, Month, Year.
     *
     * The source model is not shared in DI (each form builds its own through the object
     * manager) and each form calls it once, so no screen shows duplicates today. It would the
     * moment someone made it a singleton or called it twice in one form.
     */
    public function testCallingItTwiceDuplicatesTheOptionsWhichIsWrongButIsWhatItDoes(): void
    {
        $source = new TypeDayToYear($this->createMock(OptionFactory::class));

        $source->getAllOptions();
        $second = $source->getAllOptions();

        $this->assertCount(8, $second);
        $this->assertSame(
            ['Day', 'Week', 'Month', 'Year', 'Day', 'Week', 'Month', 'Year'],
            array_map(static fn (array $o): string => (string)$o['label'], $second)
        );
    }

    public function testThePeriodTypesIgnoreTheWithEmptyArgument(): void
    {
        $source = new TypeDayToYear($this->createMock(OptionFactory::class));

        $this->assertCount(4, $source->getAllOptions(false));
    }

    /* ---------------------------------------------------------------- helpers */

    /**
     * @param array<int,array{id:int,name:string}> $products
     */
    private function productSource(array $products, &$capturedFilter = null, &$calls = null): Product
    {
        $capturedFilter = [];
        $calls = 0;

        $filter = $this->createMock(Filter::class);
        $filterBuilder = $this->createMock(FilterBuilder::class);
        $filterBuilder->method('setField')->willReturnCallback(
            function ($field) use (&$capturedFilter, $filterBuilder) {
                $capturedFilter['field'] = $field;
                return $filterBuilder;
            }
        );
        $filterBuilder->method('setConditionType')->willReturnCallback(
            function ($condition) use (&$capturedFilter, $filterBuilder) {
                $capturedFilter['condition'] = $condition;
                return $filterBuilder;
            }
        );
        $filterBuilder->method('setValue')->willReturnCallback(
            function ($value) use (&$capturedFilter, $filterBuilder) {
                $capturedFilter['value'] = $value;
                return $filterBuilder;
            }
        );
        $filterBuilder->method('create')->willReturn($filter);

        $filterGroupBuilder = $this->createMock(FilterGroupBuilder::class);
        $filterGroupBuilder->method('addFilter')->willReturnSelf();
        $filterGroupBuilder->method('create')->willReturn($this->createMock(FilterGroup::class));

        $searchCriteria = $this->createMock(SearchCriteria::class);
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('create')->willReturn($searchCriteria);

        $items = [];
        foreach ($products as $row) {
            $product = $this->createMock(ProductInterface::class);
            $product->method('getId')->willReturn($row['id']);
            $product->method('getName')->willReturn($row['name']);
            $items[] = $product;
        }
        $results = $this->createMock(SearchResults::class);
        $results->method('getItems')->willReturn($items);

        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('getList')->willReturnCallback(
            function () use (&$calls, $results) {
                $calls++;
                return $results;
            }
        );

        return new Product(
            $this->createMock(OptionFactory::class),
            $repository,
            $searchCriteriaBuilder,
            $filterGroupBuilder,
            $filterBuilder
        );
    }

    /**
     * Labels come back as Phrase objects; compare them as strings.
     */
    private function normalise(?array $options): ?array
    {
        if ($options === null) {
            return null;
        }

        return array_map(
            static fn (array $option): array => [
                'value' => $option['value'],
                'label' => (string)$option['label'],
            ],
            $options
        );
    }
}
