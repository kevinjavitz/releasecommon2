<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Console\Command;

use Magento\Catalog\Api\ProductAttributeGroupRepositoryInterface;
use Magento\Catalog\Setup\CategorySetupFactory;
use Magento\Config\Model\ResourceModel\Config\Data;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Console\Command\UninstallRentalUseWithCaution;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bin/magento salesigniter:Uninstall`.
 *
 * This command is the most dangerous thing in the SalesIgniter suite: it removes 71 product
 * attributes, drops 8 tables including every reservation, serial number and payment row, and
 * deletes the rental store configuration. Nothing it does is recoverable.
 *
 * So the tests here are in two halves. The first half is about refusing: the command must not
 * touch anything unless somebody said yes. The second half pins exactly what it deletes when
 * they do, so that a later edit to one of those lists is a visible change rather than a silent
 * one.
 */
class UninstallRentalUseWithCautionTest extends TestCase
{
    /**
     * Every attribute the command removes, in the order it removes them.
     *
     * Transcribed from the command. If this list and the command disagree, one of them changed
     * and the diff will say which.
     */
    private const REMOVED_ATTRIBUTES = [
        'sirent_use_times',
        'sirent_use_times_grid',
        'sirent_min_global',
        'sirent_min_number',
        'sirent_min_type',
        'sirent_max_global',
        'sirent_max_number',
        'sirent_max_type',
        'sirent_turnover_before_global',
        'sirent_turnover_before_number',
        'sirent_turnover_before_type',
        'sirent_turnover_after_global',
        'sirent_turnover_after_number',
        'sirent_turnover_after_type',
        'sirent_excluded_days_global',
        'sirent_excluded_days',
        'sirent_has_shipping',
        'sirent_future_limit',
        'sirent_inv_bydate_serialized',
        'sirent_deposit_global',
        'sirent_deposit',
        'sirent_damage_waiver_global',
        'sirent_damage_waiver',
        'sirent_pricingtype',
        'sirent_price',
        'sirent_bundle_price_type',
        'sirent_quantity',
        'sirent_rental_type',
        'sirent_serial_numbers_use',
        'sirent_serial_numbers',
        'sirent_hour_next_day',
        'sirent_store_open_time',
        'sirent_store_close_time',
        'sirent_store_open_monday',
        'sirent_store_close_monday',
        'sirent_store_open_tuesday',
        'sirent_store_close_tuesday',
        'sirent_store_open_wednesday',
        'sirent_store_close_wednesday',
        'sirent_store_open_thursday',
        'sirent_store_close_thursday',
        'sirent_store_open_friday',
        'sirent_store_close_friday',
        'sirent_store_open_saturday',
        'sirent_store_close_saturday',
        'sirent_store_open_sunday',
        'sirent_store_close_sunday',
        'sirent_excludeddays_start',
        'sirent_excludeddays_end',
        'sirent_disable_shipping',
        'sirent_padding',
        'sirent_min',
        'sirent_max',
        'sirent_single_day_mode',
        'sirent_turnover_before',
        'sirent_excludeddays_from',
        'sirent_turnover_after',
        'sirent_allow_overbooking',
        'sirent_global_exclude_dates',
        'sirent_excluded_dates',
        'sirent_fixed_length',
        'sirent_enable_buyout',
        'sirent_buyout_price',
        'sirent_fixed_type',
        'sirent_excluded_days_from',
        'sirent_hotel_mode',
        'sirent_always_show',
        'sirent_special_rules',
        'sirent_autoselectstartdate',
        'sirent_pricepoints',
        'sirent_replacepricepoints',
    ];

    /**
     * Every table it drops. `sirental_inventory_grid` is the legacy one the rest of the suite
     * no longer writes to; it is still on the list, which is deliberate.
     */
    private const DROPPED_TABLES = [
        'sirental_fixed_dates',
        'sirental_fixed_names',
        'sirental_inventory_grid',
        'sirental_payment_transaction',
        'sirental_price',
        'sirental_reservationorders',
        'sirental_return',
        'sirental_serialnumber_details',
    ];

    /** @var EavSetup&\PHPUnit\Framework\MockObject\MockObject */
    private $eavSetup;

    /** @var SchemaSetupInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $schemaSetup;

    /** @var AdapterInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $schemaConnection;

    /** @var AdapterInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $resourceAdapter;

    /** @var EavSetupFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $eavSetupFactory;

    /* -------------------------------------------------------------- refusing */

    public function testItRefusesWithoutConfirmationAndDeletesNothing(): void
    {
        $command = $this->command();

        $this->eavSetupFactory->expects($this->never())->method('create');
        $this->schemaSetup->expects($this->never())->method('startSetup');
        $this->eavSetup->expects($this->never())->method('removeAttribute');
        $this->schemaConnection->expects($this->never())->method('dropTable');
        $this->resourceAdapter->expects($this->never())->method('query');

        $tester = new CommandTester($command);
        $status = $tester->execute([], ['interactive' => false]);

        $this->assertSame(1, $status, 'a refusal must be a non-zero exit status');
        $this->assertStringContainsString('Refusing to run without confirmation', $tester->getDisplay());
        $this->assertStringContainsString('Nothing was deleted', $tester->getDisplay());
    }

    public function testItSaysWhatItWillDestroyBeforeAsking(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute([], ['interactive' => false]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('permanently deletes', $display);
        $this->assertStringContainsString('sirental_*', $display);
        $this->assertStringContainsString('cannot be undone', $display);
    }

    public function testAnsweringAnythingButYesDeletesNothing(): void
    {
        $command = $this->command();
        $this->eavSetup->expects($this->never())->method('removeAttribute');
        $this->schemaConnection->expects($this->never())->method('dropTable');

        $tester = new CommandTester($command);
        $tester->setInputs(['n']);
        $status = $tester->execute([], ['interactive' => true]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Nothing was deleted', $tester->getDisplay());
    }

    public function testAnsweringYesLetsItThrough(): void
    {
        $command = $this->command();
        $this->eavSetup->expects($this->atLeastOnce())->method('removeAttribute');

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);

        $this->assertSame(0, $tester->execute([], ['interactive' => true]));
    }

    public function testForceSkipsThePromptEntirely(): void
    {
        $command = $this->command();
        $this->eavSetup->expects($this->atLeastOnce())->method('removeAttribute');

        $tester = new CommandTester($command);
        $status = $tester->execute(['--force' => true], ['interactive' => false]);

        $this->assertSame(0, $status);
        $this->assertStringNotContainsString('Refusing to run', $tester->getDisplay());
    }

    /* ------------------------------------------------- what it deletes when told to */

    public function testItRemovesExactlyTheseSeventyOneProductAttributes(): void
    {
        $command = $this->command();
        $removed = [];
        $this->eavSetup->method('removeAttribute')
            ->willReturnCallback(function ($entityType, $code) use (&$removed) {
                $this->assertSame(\Magento\Catalog\Model\Product::ENTITY, $entityType);
                $removed[] = $code;
                return $this->eavSetup;
            });

        (new CommandTester($command))->execute(['--force' => true], ['interactive' => false]);

        $this->assertSame(self::REMOVED_ATTRIBUTES, $removed);
        $this->assertCount(71, $removed);
    }

    public function testItDropsExactlyTheseEightTables(): void
    {
        $command = $this->command();
        $dropped = [];
        $this->schemaConnection->method('dropTable')
            ->willReturnCallback(function ($table) use (&$dropped) {
                $dropped[] = $table;
                return true;
            });

        (new CommandTester($command))->execute(['--force' => true], ['interactive' => false]);

        $this->assertSame(self::DROPPED_TABLES, $dropped);
        $this->assertContains(
            'sirental_inventory_grid',
            $dropped,
            'the legacy inventory grid is still dropped; the module CLAUDE.md says so'
        );
    }

    public function testItRemovesTheRentalAttributeGroupFromTheDefaultAttributeSet(): void
    {
        $command = $this->command();

        $this->eavSetup->expects($this->once())->method('getEntityTypeId')
            ->with(\Magento\Catalog\Model\Product::ENTITY)->willReturn(4);
        $this->eavSetup->expects($this->once())->method('getDefaultAttributeSetId')
            ->with(4)->willReturn(9);
        $this->eavSetup->expects($this->once())->method('getAttributeGroupId')
            ->with(4, 9, 'Rental')->willReturn(77);
        $this->eavSetup->expects($this->once())->method('removeAttributeGroup')
            ->with(4, 9, 77);

        (new CommandTester($command))->execute(['--force' => true], ['interactive' => false]);
    }

    public function testItDeletesTheRentalConfigAndBothSetupModuleRows(): void
    {
        $command = $this->command();
        $queries = [];
        $this->resourceAdapter->method('query')
            ->willReturnCallback(function ($sql) use (&$queries) {
                $queries[] = $sql;
                return null;
            });

        (new CommandTester($command))->execute(['--force' => true], ['interactive' => false]);

        $this->assertCount(3, $queries);
        $this->assertStringContainsString("DELETE FROM core_config_data WHERE path LIKE 'salesigniter_rental/%'", $queries[0]);
        $this->assertStringContainsString("DELETE FROM setup_module WHERE module LIKE 'SalesIgniter_Rental'", $queries[1]);
        // It removes its OWN setup_module row too, not just the rental one.
        $this->assertStringContainsString("DELETE FROM setup_module WHERE module LIKE 'SalesIgniter_Common'", $queries[2]);
    }

    public function testTheCommandIsNamedAndDescribedAsRegisteredInDiXml(): void
    {
        $command = $this->command();

        $this->assertSame('salesigniter:Uninstall', $command->getName());
        $this->assertStringStartsWith('Caution:', $command->getDescription());
        $this->assertTrue($command->getDefinition()->hasOption('force'));
    }

    /* ---------------------------------------------------------------- helpers */

    private function command(): UninstallRentalUseWithCaution
    {
        $this->eavSetup = $this->createMock(EavSetup::class);
        $this->eavSetup->method('removeAttribute')->willReturn($this->eavSetup);
        $this->eavSetup->method('getEntityTypeId')->willReturn(4);
        $this->eavSetup->method('getDefaultAttributeSetId')->willReturn(9);
        $this->eavSetup->method('getAttributeGroupId')->willReturn(77);

        $this->eavSetupFactory = $this->createMock(EavSetupFactory::class);
        $this->eavSetupFactory->method('create')->willReturn($this->eavSetup);

        $this->schemaConnection = $this->createMock(AdapterInterface::class);
        $this->schemaConnection->method('dropTable')->willReturn(true);

        $this->schemaSetup = $this->createMock(SchemaSetupInterface::class);
        $this->schemaSetup->method('startSetup')->willReturnSelf();
        $this->schemaSetup->method('getConnection')->willReturn($this->schemaConnection);

        $this->resourceAdapter = $this->createMock(AdapterInterface::class);
        $this->resourceAdapter->method('getTableName')->willReturnArgument(0);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->resourceAdapter);

        return new UninstallRentalUseWithCaution(
            $this->createMock(CategorySetupFactory::class),
            $this->createMock(ProductAttributeGroupRepositoryInterface::class),
            $this->createMock(AttributeRepositoryInterface::class),
            $this->createMock(CollectionFactory::class),
            $this->createMock(Data::class),
            $this->schemaSetup,
            $this->createMock(ObjectManagerInterface::class),
            $this->eavSetupFactory,
            $resourceConnection
        );
    }
}
