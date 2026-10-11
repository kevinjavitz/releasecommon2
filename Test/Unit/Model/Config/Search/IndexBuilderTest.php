<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Config\Search;

use Magento\Backend\Model\UrlInterface;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Group;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Config\Model\Config\Structure\Element\Tab;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Config\Search\IndexBuilder;

/**
 * The settings search's index, built from a hand-made config structure. The structure's real iterators only
 * yield what the admin may open in the current scope, so what the builder is handed is already filtered; these
 * tests pin what it makes of it.
 */
class IndexBuilderTest extends TestCase
{
    /* ------------------------------------------------------------------------------------------------ helpers */

    /** @param array<string, mixed> $data */
    private function field(string $path, array $data = []): Field
    {
        $id = basename($path);
        $data += ['id' => $id, 'path' => dirname($path), '_elementType' => 'field'];
        $field = $this->createStub(Field::class);
        $field->method('getId')->willReturn($id);
        $field->method('getPath')->willReturn($path);
        $field->method('getData')->willReturn($data);
        $field->method('getLabel')->willReturn((string)($data['label'] ?? ''));
        $field->method('getComment')->willReturn(is_string($data['comment'] ?? null) ? $data['comment'] : '');
        $field->method('getTooltip')->willReturn(is_string($data['tooltip'] ?? null) ? $data['tooltip'] : '');
        $field->method('getHint')->willReturn((string)($data['hint'] ?? ''));

        return $field;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<Field|Group> $children
     */
    private function group(string $path, array $data, array $children): Group
    {
        $id = basename($path);
        $data += ['id' => $id, 'path' => dirname($path), '_elementType' => 'group'];
        $group = $this->createStub(Group::class);
        $group->method('getId')->willReturn($id);
        $group->method('getPath')->willReturn($path);
        $group->method('getData')->willReturn($data);
        $group->method('getLabel')->willReturn((string)($data['label'] ?? ''));
        $group->method('getComment')->willReturn(is_string($data['comment'] ?? null) ? $data['comment'] : '');
        $group->method('getChildren')->willReturnCallback(static fn () => new \ArrayIterator($children));

        return $group;
    }

    /** @param list<Group> $groups */
    private function section(string $id, string $label, array $groups): Section
    {
        $section = $this->createStub(Section::class);
        $section->method('getId')->willReturn($id);
        $section->method('getLabel')->willReturn($label);
        $section->method('getChildren')->willReturnCallback(static fn () => new \ArrayIterator($groups));

        return $section;
    }

    /** @param list<Section> $sections */
    private function tab(string $id, array $sections): Tab
    {
        $tab = $this->createStub(Tab::class);
        $tab->method('getId')->willReturn($id);
        $tab->method('getChildren')->willReturnCallback(static fn () => new \ArrayIterator($sections));

        return $tab;
    }

    /** @param list<Tab> $tabs */
    private function builder(array $tabs, ?array $searchTabs = null): IndexBuilder
    {
        $structure = $this->createStub(Structure::class);
        $structure->method('getTabs')->willReturnCallback(static fn () => new \ArrayIterator($tabs));
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params) => 'https://shop.test/admin/' . $route . '?' . http_build_query($params)
        );

        return $searchTabs === null ? new IndexBuilder($structure, $url) : new IndexBuilder($structure, $url, $searchTabs);
    }

    /** a small rental-like section and a second one, as the tests below use them */
    private function rentalTabs(): array
    {
        $rental = $this->section('salesigniter_rental', 'Rental', [
            $this->group('salesigniter_rental/late_fee', [
                'label' => 'Late fees',
                'comment' => 'Charge a fee when a rental comes back <strong>late</strong>.',
            ], [
                $this->field('salesigniter_rental/late_fee/enabled', ['label' => 'Charge late fees']),
                $this->field('salesigniter_rental/late_fee/amount', [
                    'label' => 'Fee per day',
                    'comment' => "In the store's  currency.\n<br/>0 = no fee",
                    'tooltip' => 'Charged for each day &amp; part day',
                    'depends' => ['fields' => ['enabled' => [
                        'id' => 'salesigniter_rental/late_fee/enabled', 'value' => '1',
                        'dependPath' => ['salesigniter_rental', 'late_fee', 'enabled'],
                    ]]],
                ]),
            ]),
            $this->group('salesigniter_rental/advanced', ['label' => 'Advanced settings'], [
                $this->field('salesigniter_rental/advanced/grid_points', [
                    'label' => 'Number of price points in the admin product grid',
                    'config_path' => 'salesigniter_rental/price/grid_points',
                ]),
            ]),
        ]);
        $subscriptions = $this->section('sisubscriptions', 'Subscriptions', [
            $this->group('sisubscriptions/dunning', ['label' => 'Failed payments'], [
                $this->field('sisubscriptions/dunning/retries', ['label' => 'Retries', 'comment' => 'Days between tries']),
            ]),
        ]);

        return [
            $this->tab('general', [$this->section('general', 'General', [])]),
            $this->tab('salesigniter', [$rental, $subscriptions]),
            $this->tab('catalog', [$this->section('catalog', 'Catalog', [])]),
        ];
    }

    /* ---------------------------------------------------------------------------------------------- the index */

    public function testOnlyTheSalesIgniterTabsSectionsAreIndexedInTheirOrder(): void
    {
        $index = $this->builder($this->rentalTabs())->build();

        self::assertSame(['salesigniter_rental', 'sisubscriptions'], array_column($index['sections'], 'id'));
        self::assertSame(['Rental', 'Subscriptions'], array_column($index['sections'], 'label'));
    }

    public function testEachSectionLinksToItsOwnPageInTheCurrentScope(): void
    {
        $index = $this->builder($this->rentalTabs())->build();

        // the left column's own link: same route, current params (website / store) kept, section swapped
        self::assertSame(
            'https://shop.test/admin/*/*/*?' . http_build_query(['_current' => true, 'section' => 'sisubscriptions']),
            $index['sections'][1]['url']
        );
    }

    public function testGroupsCarryTheirDomIdLabelAndPlainComment(): void
    {
        $groups = $this->builder($this->rentalTabs())->build()['sections'][0]['groups'];

        self::assertSame([
            ['id' => 'salesigniter_rental_late_fee', 'label' => 'Late fees', 'comment' => 'Charge a fee when a rental comes back late.'],
            ['id' => 'salesigniter_rental_advanced', 'label' => 'Advanced settings'],
        ], $groups);
    }

    public function testAFieldCarriesWhatTheSearchMatchesOn(): void
    {
        $fields = $this->builder($this->rentalTabs())->build()['sections'][0]['fields'];

        self::assertSame([
            'path' => 'salesigniter_rental/late_fee/amount',
            'label' => 'Fee per day',
            'comment' => "In the store's currency. 0 = no fee",
            'tooltip' => 'Charged for each day & part day',
            'depends' => ['salesigniter_rental_late_fee_enabled'],
        ], $fields[1]);
        // nothing empty is sent, and nothing the path already says (the field's DOM id and its group's)
        self::assertSame(['path', 'label'], array_keys($fields[0]));
    }

    public function testASettingSavedElsewhereCarriesItsConfigPath(): void
    {
        $fields = $this->builder($this->rentalTabs())->build()['sections'][0]['fields'];

        self::assertSame('salesigniter_rental/advanced/grid_points', $fields[2]['path']);
        self::assertSame('salesigniter_rental/price/grid_points', $fields[2]['config_path']);
    }

    public function testAConfigPathEqualToThePathIsLeftOut(): void
    {
        $tabs = [$this->tab('salesigniter', [$this->section('s', 'S', [
            $this->group('s/g', ['label' => 'G'], [
                $this->field('s/g/f', ['label' => 'F', 'config_path' => 's/g/f']),
            ]),
        ])])];

        self::assertArrayNotHasKey('config_path', $this->builder($tabs)->build()['sections'][0]['fields'][0]);
    }

    public function testDependenciesBecomeDomIdsBareIdsBeingTakenFromTheSameGroup(): void
    {
        $tabs = [$this->tab('salesigniter', [$this->section('s', 'S', [
            $this->group('s/g', ['label' => 'G'], [
                $this->field('s/g/f', ['label' => 'F', 'depends' => ['fields' => [
                    'a' => ['id' => 's/other/a', 'value' => '1'],
                    'b' => ['id' => 'b', 'value' => '1'],
                    'c' => '1',
                    'd' => ['id' => 's/other/a', 'value' => '2'],
                ]]]),
            ]),
        ])])];

        self::assertSame(
            ['s_other_a', 's_g_b', 's_g_c'],
            $this->builder($tabs)->build()['sections'][0]['fields'][0]['depends']
        );
    }

    public function testACommentModelAndATooltipBlockAreNeverRun(): void
    {
        $field = $this->createMock(Field::class);
        $field->method('getPath')->willReturn('s/g/key');
        $field->method('getLabel')->willReturn('Licence key');
        $field->method('getData')->willReturn([
            'id' => 'key', 'path' => 's/g', '_elementType' => 'field', 'label' => 'Licence key',
            'comment' => ['model' => 'Vendor\\Comment\\Model'],
            'tooltip_block' => 'Vendor\\Tooltip\\Block',
        ]);
        // a comment model needs the stored value and may call out; a tooltip block renders a block
        $field->expects(self::never())->method('getComment');
        $field->expects(self::never())->method('getTooltip');
        $tabs = [$this->tab('salesigniter', [$this->section('s', 'S', [
            $this->group('s/g', ['label' => 'G'], [$field]),
        ])])];

        $record = $this->builder($tabs)->build()['sections'][0]['fields'][0];

        self::assertSame('Licence key', $record['label']);
        self::assertArrayNotHasKey('comment', $record);
        self::assertArrayNotHasKey('tooltip', $record);
    }

    public function testAHintIsSearchedToo(): void
    {
        $tabs = [$this->tab('salesigniter', [$this->section('s', 'S', [
            $this->group('s/g', ['label' => 'G'], [
                $this->field('s/g/f', ['label' => 'F', 'hint' => 'Shown <em>under</em> the box']),
            ]),
        ])])];

        self::assertSame('Shown under the box', $this->builder($tabs)->build()['sections'][0]['fields'][0]['hint']);
    }

    public function testAFieldWithNothingToFindIsLeftOut(): void
    {
        $tabs = [$this->tab('salesigniter', [$this->section('s', 'S', [
            $this->group('s/g', ['label' => 'G'], [
                $this->field('s/g/heading', []),
                $this->field('s/g/button', ['comment' => 'Runs the import']),
            ]),
        ])])];

        $fields = $this->builder($tabs)->build()['sections'][0]['fields'];

        self::assertSame(['s/g/button'], array_column($fields, 'path'));
    }

    public function testANestedGroupNamesItsParentAndItsFieldsTheirOwnGroup(): void
    {
        $tabs = [$this->tab('salesigniter', [$this->section('s', 'S', [
            $this->group('s/outer', ['label' => 'Outer'], [
                $this->field('s/outer/a', ['label' => 'A']),
                $this->group('s/outer/inner', ['label' => 'Inner', 'comment' => 'Inside'], [
                    $this->field('s/outer/inner/b', ['label' => 'B']),
                ]),
                $this->field('s/outer/c', ['label' => 'C']),
            ]),
        ])])];

        $section = $this->builder($tabs)->build()['sections'][0];

        self::assertSame([
            ['id' => 's_outer', 'label' => 'Outer'],
            ['id' => 's_outer_inner', 'label' => 'Inner', 'comment' => 'Inside', 'parent' => 's_outer'],
        ], $section['groups']);
        // a field's group is the DOM id of its parent path, nested or not
        self::assertSame(
            [['s/outer/a', 's_outer'], ['s/outer/inner/b', 's_outer_inner'], ['s/outer/c', 's_outer']],
            array_map(static fn ($f) => [$f['path'], IndexBuilder::domId(dirname($f['path']))], $section['fields'])
        );
    }

    /**
     * Magento's structure iterators are flyweights: one element object per iterator, its data replaced on every
     * step. Whatever the builder kept a reference to would end up describing the last element.
     */
    public function testFlyweightElementsAreCopiedBeforeTheIteratorMovesOn(): void
    {
        $current = new \ArrayObject(['path' => '', 'label' => '', 'comment' => '']);
        $flyweight = $this->createStub(Field::class);
        $flyweight->method('getPath')->willReturnCallback(static fn () => $current['path']);
        $flyweight->method('getLabel')->willReturnCallback(static fn () => $current['label']);
        $flyweight->method('getComment')->willReturnCallback(static fn () => $current['comment']);
        $flyweight->method('getData')->willReturnCallback(static fn () => [
            'id' => basename($current['path']), 'path' => dirname($current['path']), '_elementType' => 'field',
            'label' => $current['label'], 'comment' => $current['comment'],
        ]);
        $rows = [
            ['path' => 's/g/one', 'label' => 'One', 'comment' => 'First'],
            ['path' => 's/g/two', 'label' => 'Two', 'comment' => 'Second'],
            ['path' => 's/g/three', 'label' => 'Three', 'comment' => 'Third'],
        ];
        $iterator = new class ($rows, $current, $flyweight) implements \Iterator {
            private int $at = 0;

            public function __construct(private array $rows, private \ArrayObject $current, private object $flyweight)
            {
            }

            public function current(): mixed
            {
                $this->current->exchangeArray($this->rows[$this->at]);

                return $this->flyweight;
            }

            public function next(): void
            {
                $this->at++;
            }

            public function key(): mixed
            {
                return $this->at;
            }

            public function valid(): bool
            {
                return $this->at < count($this->rows);
            }

            public function rewind(): void
            {
                $this->at = 0;
            }
        };
        $group = $this->createStub(Group::class);
        $group->method('getPath')->willReturn('s/g');
        $group->method('getLabel')->willReturn('G');
        $group->method('getData')->willReturn(['id' => 'g', 'path' => 's', '_elementType' => 'group']);
        $group->method('getChildren')->willReturn($iterator);
        $tabs = [$this->tab('salesigniter', [$this->section('s', 'S', [$group])])];

        $fields = $this->builder($tabs)->build()['sections'][0]['fields'];

        self::assertSame(
            [['s/g/one', 'One', 'First'], ['s/g/two', 'Two', 'Second'], ['s/g/three', 'Three', 'Third']],
            array_map(static fn ($f) => [$f['path'], $f['label'], $f['comment']], $fields)
        );
    }

    public function testSectionIdsListsTheSearchableSectionsWithoutReadingTheirFields(): void
    {
        $section = $this->createMock(Section::class);
        $section->method('getId')->willReturn('salesigniter_rfq');
        $section->expects(self::never())->method('getChildren');
        $tabs = [$this->tab('general', [$this->section('general', 'General', [])]), $this->tab('salesigniter', [$section])];

        self::assertSame(['salesigniter_rfq'], $this->builder($tabs)->sectionIds());
    }

    public function testTheTabsAreAnArgument(): void
    {
        $index = $this->builder($this->rentalTabs(), ['catalog', ''])->build();

        self::assertSame(['catalog'], array_column($index['sections'], 'id'));
    }

    public function testNoSalesIgniterTabMeansAnEmptyIndex(): void
    {
        self::assertSame(['sections' => []], $this->builder([$this->tab('general', [])])->build());
    }

    public function testTheIndexIsPlainJsonData(): void
    {
        $index = $this->builder($this->rentalTabs())->build();

        // no objects (Phrase, flyweights) left inside
        self::assertSame($index, json_decode((string)json_encode($index), true));
    }

    /* --------------------------------------------------------------------------------------------------- text */

    /** @return array<string, array{string, string}> */
    public static function texts(): array
    {
        return [
            'tags out, words kept apart at a break' => ['Line one<br>line two', 'Line one line two'],
            'a link keeps its words' => ['See <a href="https://x.test/">My Account &gt; Licenses</a>.', 'See My Account > Licenses.'],
            'entities decoded' => ['Tom &amp; Jerry&nbsp;&quot;q&quot; &#39;s', "Tom & Jerry \"q\" 's"],
            'white space squashed' => ["  a\n\t b  ", 'a b'],
            'paragraphs' => ['<p>One</p><p>Two</p>', 'One Two'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('texts')]
    public function testTextIsPlain(string $html, string $expected): void
    {
        self::assertSame($expected, IndexBuilder::text($html));
    }

    public function testDomIdIsMagentosElementId(): void
    {
        self::assertSame('salesigniter_rental_late_fee_amount', IndexBuilder::domId('salesigniter_rental/late_fee/amount'));
    }
}
