<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Console\Command;

use Magento\Catalog\Api\ProductAttributeGroupRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Setup\CategorySetupFactory;
use Magento\Config\Model\ResourceModel\Config\Data;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Console\Command\RemoveAttributeSourceModels;
use SalesIgniter\Common\Console\Command\RestoreAttributeSourceModels;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `salesigniter:Remove:Attributes` and `salesigniter:Restore:Attributes`.
 *
 * The pair exists so that SalesIgniter_Rental can be disabled without breaking the admin:
 * every rental attribute has a backend_model / source_model pointing at a class inside that
 * module, and an attribute whose source model no longer exists takes the product grid and the
 * product form down with it. Remove nulls those two columns; Restore puts them back.
 *
 * Neither writes any data and neither drops anything, so they are safe to run - but they are
 * only safe as a pair, and Restore has to know the mapping Remove threw away. That mapping is
 * hardcoded in Restore, so these tests pin it.
 */
class AttributeSourceModelCommandsTest extends TestCase
{
    /** @var EavSetup&\PHPUnit\Framework\MockObject\MockObject */
    private $eavSetup;

    /** @var array<int,array{code:string,key:string,value:mixed}> */
    private $updates = [];

    /** @var string[] Attribute codes the test pretends exist on this install. */
    private $existingAttributes = [];

    /* ------------------------------------------------------------------ remove */

    public function testRemoveIsNamedAndDescribedAsRegisteredInDiXml(): void
    {
        $command = $this->removeCommand();

        $this->assertSame('salesigniter:Remove:Attributes', $command->getName());
        $this->assertSame(
            'Disables rental attribute source models when disabling module.',
            $command->getDescription()
        );
    }

    public function testRemoveNullsBothModelColumnsOnEveryRentalAttribute(): void
    {
        $this->existingAttributes = ['sirent_use_times', 'sirent_price'];

        $status = (new CommandTester($this->removeCommand()))->execute([]);

        $this->assertSame(0, $status);
        foreach (['sirent_use_times', 'sirent_price'] as $code) {
            $this->assertContains(
                ['code' => $code, 'key' => 'backend_model', 'value' => null],
                $this->updates
            );
            $this->assertContains(
                ['code' => $code, 'key' => 'source_model', 'value' => null],
                $this->updates
            );
        }
    }

    public function testRemoveSkipsAttributesThisInstallDoesNotHave(): void
    {
        // Nothing exists: getAttributeId() answers 0 for every code.
        $this->existingAttributes = [];

        $status = (new CommandTester($this->removeCommand()))->execute([]);

        $this->assertSame(0, $status);
        $this->assertSame([], $this->updates, 'no attribute exists, so nothing may be updated');
    }

    public function testRemoveWalksFiftyFourCodesOfWhichOneIsListedTwice(): void
    {
        // Every code exists, so every code is written. The list in the command has 54 entries
        // and 53 distinct codes: sirent_autoselectstartdate appears twice. Harmless (the
        // second write is identical) but it is there, and a future edit that "tidies" the list
        // will show up here. 1.2.56 added sirent_minmaxhidecalendar, the 53rd; 1.2.59
        // sirent_charge_return_day, the 54th.
        $this->existingAttributes = ['*'];

        (new CommandTester($this->removeCommand()))->execute([]);

        $codes = array_column($this->updates, 'code');

        // Two writes (backend_model, source_model) per list entry: 54 entries -> 108 writes.
        $this->assertCount(108, $this->updates);
        $this->assertSame(53, count(array_unique($codes)), 'the list has 54 entries but 53 distinct codes');
        $this->assertSame(
            4,
            count(array_keys($codes, 'sirent_autoselectstartdate', true)),
            'sirent_autoselectstartdate is listed twice, so it is written twice per column'
        );
    }

    /**
     * The attribute the list used to miss. Its backend model needs the rental module's DI, so
     * with SalesIgniter_Rental disabled and this left in place every product page answered 500
     * ("Cannot instantiate interface ...FixedRentalDatesRepositoryInterface") - measured on a
     * copy of the dev install, 2026-09-24.
     */
    public function testRemoveClearsSirentMinmaxhidecalendar(): void
    {
        $this->existingAttributes = ['*'];

        (new CommandTester($this->removeCommand()))->execute([]);

        $this->assertContains(
            ['code' => 'sirent_minmaxhidecalendar', 'key' => 'backend_model', 'value' => null],
            $this->updates
        );
    }

    /**
     * "Also charge the return day" (rental 1.2.214) has the same SirentBackendConfig backend as
     * sirent_minmaxhidecalendar, so left in place it would keep the product page dead the same way.
     */
    public function testRemoveClearsSirentChargeReturnDay(): void
    {
        $this->existingAttributes = ['*'];

        (new CommandTester($this->removeCommand()))->execute([]);

        foreach (['backend_model', 'source_model'] as $key) {
            $this->assertContains(
                ['code' => 'sirent_charge_return_day', 'key' => $key, 'value' => null],
                $this->updates
            );
        }
    }

    public function testRemoveReportsWhatItDid(): void
    {
        $this->existingAttributes = ['*'];
        $tester = new CommandTester($this->removeCommand());
        $tester->execute([]);

        $this->assertStringContainsString('Attribute models removed', $tester->getDisplay());
    }

    /* ----------------------------------------------------------------- restore */

    public function testRestoreIsNamedAndDescribedAsRegisteredInDiXml(): void
    {
        $command = $this->restoreCommand();

        $this->assertSame('salesigniter:Restore:Attributes', $command->getName());
        $this->assertSame(
            'Restores rental attribute source models when enabling module.',
            $command->getDescription()
        );
    }

    /**
     * The mapping Restore has to get right. Every value here is a class in
     * SalesIgniter_Rental; if one is renamed there and not here, the attribute comes back
     * pointing at a class that does not exist and the product form dies on open.
     *
     */
    #[DataProvider('restoredModels')]
    public function testRestorePutsBackTheRightModelForEachAttribute(
        string $code,
        string $key,
        string $expected
    ): void {
        $this->existingAttributes = ['*'];

        (new CommandTester($this->restoreCommand()))->execute([]);

        $this->assertContains(
            ['code' => $code, 'key' => $key, 'value' => $expected],
            $this->updates
        );
    }

    public static function restoredModels(): array
    {
        $backend = 'backend_model';
        $source = 'source_model';
        $ns = 'SalesIgniter\Rental\Model\Attribute\\';

        return [
            'the plain config-backed ones share one backend model' => [
                'sirent_hotel_mode', $backend, $ns . 'Backend\SirentBackendConfig',
            ],
            'minmaxhidecalendar, added to both lists in 1.2.56' => [
                'sirent_minmaxhidecalendar', $backend, $ns . 'Backend\SirentBackendConfig',
            ],
            'damage waiver too - the -200 sentinel lives behind this' => [
                'sirent_damage_waiver', $backend, $ns . 'Backend\SirentBackendConfig',
            ],
            'excluded days is a multiselect' => [
                'sirent_excluded_days', $backend, $ns . 'Backend\Multiselect',
            ],
            'and gets the week source' => [
                'sirent_excluded_days', $source, $ns . 'Sources\ExcludedDaysWeek',
            ],
            'excludeddays_from gets a DIFFERENT source' => [
                'sirent_excludeddays_from', $source, $ns . 'Sources\ExcludedDaysWeekFrom',
            ],
            'special rules' => [
                'sirent_special_rules', $source, $ns . 'Sources\SpecialPricingRules',
            ],
            'price has its own backend model' => [
                'sirent_price', $backend, $ns . 'Backend\RentalPrice',
            ],
            'serial numbers has its own backend model' => [
                'sirent_serial_numbers', $backend, $ns . 'Backend\SerialNumbers',
            ],
            'excluded dates has its own backend model' => [
                'sirent_excluded_dates', $backend, $ns . 'Backend\ExcludedDates',
            ],
            'fixed type is the one with both' => [
                'sirent_fixed_type', $source, $ns . 'Sources\FixedType',
            ],
            'pricing type is source only' => [
                'sirent_pricingtype', $source, $ns . 'Sources\PricingType',
            ],
            'rental type is source only' => [
                'sirent_rental_type', $source, $ns . 'Sources\RentalType',
            ],
            'bundle price type is source only' => [
                'sirent_bundle_price_type', $source, $ns . 'Sources\BundlePriceType',
            ],
            'use times is source only' => [
                'sirent_use_times', $source, $ns . 'Sources\UseTimes',
            ],
            'store hours are Time-backed' => [
                'sirent_store_open_monday', $backend, $ns . 'Backend\Time',
            ],
            'including the next-day hour' => [
                'sirent_hour_next_day', $backend, $ns . 'Backend\Time',
            ],
        ];
    }

    /**
     * Rental 1.2.214 made sirent_hotel_mode "Booked by", a select over Sources\BookedBy, and added
     * sirent_charge_return_day over Sources\ChargeReturnDay. Until 1.2.59 Restore gave
     * sirent_hotel_mode a blank source, so the Booked by select came back with no choices.
     */
    public function testRestoreGivesBookedByAndChargeReturnDayTheirSources(): void
    {
        $this->existingAttributes = ['*'];
        $ns = 'SalesIgniter\Rental\Model\Attribute\\';

        (new CommandTester($this->restoreCommand(true)))->execute([]);

        $this->assertSame([$ns . 'Sources\BookedBy'], $this->written('sirent_hotel_mode', 'source_model'));
        $this->assertSame([$ns . 'Backend\SirentBackendConfig'], $this->written('sirent_hotel_mode', 'backend_model'));
        $this->assertSame([$ns . 'Sources\ChargeReturnDay'], $this->written('sirent_charge_return_day', 'source_model'));
        $this->assertSame(
            [$ns . 'Backend\SirentBackendConfig'],
            $this->written('sirent_charge_return_day', 'backend_model')
        );
    }

    /**
     * Common can be installed next to a rental older than 1.2.214, whose sirent_hotel_mode is still
     * the Yes/No "Enable Hotel Mode" with no source. A source naming a class that does not exist
     * takes the product form down, so there it stays blank, as before.
     */
    public function testRestoreLeavesHotelModesSourceBlankOnAnOlderRental(): void
    {
        $this->existingAttributes = ['sirent_hotel_mode'];

        (new CommandTester($this->restoreCommand(false)))->execute([]);

        $this->assertSame([''], $this->written('sirent_hotel_mode', 'source_model'));
        $this->assertSame([], $this->written('sirent_charge_return_day', 'source_model'), 'not on this install');
    }

    public function testTheBookedBySourceIsTheRealClassName(): void
    {
        $this->assertSame(
            'SalesIgniter\Rental\Model\Attribute\Sources\BookedBy',
            RestoreAttributeSourceModels::BOOKED_BY_SOURCE
        );
        $this->assertSame(
            'SalesIgniter\Rental\Model\Attribute\Sources\ChargeReturnDay',
            RestoreAttributeSourceModels::CHARGE_RETURN_DAY_SOURCE
        );
    }

    public function testRestoreReappliesTheSeventeenTimeAttributesToSirentAndBundle(): void
    {
        $this->existingAttributes = ['*'];

        (new CommandTester($this->restoreCommand()))->execute([]);

        $applyTo = array_values(array_filter(
            $this->updates,
            static fn (array $u): bool => $u['key'] === 'apply_to'
        ));

        $this->assertCount(17, $applyTo);
        foreach ($applyTo as $update) {
            $this->assertSame('sirent,bundle', $update['value']);
        }
        $this->assertContains('sirent_store_close_sunday', array_column($applyTo, 'code'));
    }

    public function testRestoreSkipsAttributesThisInstallDoesNotHave(): void
    {
        $this->existingAttributes = [];

        $status = (new CommandTester($this->restoreCommand()))->execute([]);

        $this->assertSame(0, $status);
        $this->assertSame([], $this->updates);
    }

    public function testRestoreReportsWhatItDid(): void
    {
        $this->existingAttributes = ['*'];
        $tester = new CommandTester($this->restoreCommand());
        $tester->execute([]);

        $this->assertStringContainsString('Attribute models restored', $tester->getDisplay());
    }

    /* ---------------------------------------------------------------- helpers */

    private function removeCommand(): RemoveAttributeSourceModels
    {
        $args = $this->constructorArgs();

        return new RemoveAttributeSourceModels(...$args);
    }

    /**
     * @param bool|null $rentalHasBookedBy null: ask the autoloader (the rental module installed here);
     *                                     true / false: a rental with or without the 1.2.214 classes
     */
    private function restoreCommand(?bool $rentalHasBookedBy = null): RestoreAttributeSourceModels
    {
        $args = $this->constructorArgs();
        if ($rentalHasBookedBy === null) {
            return new RestoreAttributeSourceModels(...$args);
        }
        $command = new class (...$args) extends RestoreAttributeSourceModels {
            /** @var bool */
            public $hasClasses = true;

            protected function classExists(string $class): bool
            {
                return $this->hasClasses;
            }
        };
        $command->hasClasses = $rentalHasBookedBy;

        return $command;
    }

    /** @return array<int,mixed> the values written to one attribute's column, in order */
    private function written(string $code, string $key): array
    {
        return array_values(array_map(
            static fn (array $u) => $u['value'],
            array_filter($this->updates, static fn (array $u): bool => $u['code'] === $code && $u['key'] === $key)
        ));
    }

    /**
     * Both commands take the same ten arguments in the same order - they are copies of one
     * another - so one builder serves both.
     *
     * @return array<int,mixed>
     */
    private function constructorArgs(): array
    {
        $this->updates = [];

        $this->eavSetup = $this->createMock(EavSetup::class);
        $this->eavSetup->method('getAttributeId')->willReturnCallback(
            function ($entityType, $code) {
                $this->assertSame(Product::ENTITY, $entityType);
                if ($this->existingAttributes === ['*']) {
                    return 123;
                }
                return in_array($code, $this->existingAttributes, true) ? 123 : 0;
            }
        );
        $this->eavSetup->method('updateAttribute')->willReturnCallback(
            function ($entityType, $code, $key, $value) {
                $this->assertSame(Product::ENTITY, $entityType);
                $this->updates[] = ['code' => $code, 'key' => $key, 'value' => $value];
                return $this->eavSetup;
            }
        );

        $eavSetupFactory = $this->createMock(EavSetupFactory::class);
        $eavSetupFactory->method('create')->willReturn($this->eavSetup);

        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('startSetup')->willReturnSelf();

        return [
            $this->createMock(CategorySetupFactory::class),
            $this->createMock(ProductAttributeGroupRepositoryInterface::class),
            $this->createMock(AttributeRepositoryInterface::class),
            $this->createMock(CollectionFactory::class),
            $this->createMock(Data::class),
            $this->createMock(SchemaSetupInterface::class),
            $this->createMock(ObjectManagerInterface::class),
            $eavSetupFactory,
            $this->createMock(ResourceConnection::class),
            $moduleDataSetup,
        ];
    }
}
