<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Setup\ModuleRemoval;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SalesIgniter\Common\Setup\ModuleRemoval\ModuleFiles;
use SalesIgniter\Common\Setup\ModuleRemoval\ModuleRecordsRemover;

/**
 * patch_list: without these rows gone a reinstall skips every data patch, and with a revertable
 * patch's row gone first Magento's own revert step throws.
 */
class ModuleRecordsRemoverTest extends TestCase
{
    public function testOnlyThisModulesNonRevertablePatchesAreForgotten(): void
    {
        $deleted = null;
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('isTableExists')->willReturn(true);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn([
            'SalesIgniter\\Rental\\Setup\\Patch\\Data\\GrantDashboardAcl',
            'SalesIgniter\\Rental\\Setup\\Patch\\Schema\\AddReferentialIntegrity',
            'SalesIgniter\\Rentalinventory\\Setup\\Patch\\Schema\\DropStoredInventoryArrayTables',
            'SalesIgniter\\RentalContract\\Setup\\Patch\\Data\\Something',
            'Magento\\Catalog\\Setup\\Patch\\Data\\InstallDefaultCategories',
            RevertableFixturePatch::class,
        ]);
        $connection->method('delete')->willReturnCallback(function ($table, $where) use (&$deleted) {
            $deleted = [$table, $where];
            return count($where['patch_name IN (?)']);
        });
        $setup = $this->createStub(SchemaSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(function ($table) {
            return 'mgaq_' . $table;
        });

        $remover = new ModuleRecordsRemover($this->createStub(ModuleFiles::class), new NullLogger());
        $this->assertSame(2, $remover->removePatchHistory($setup, 'SalesIgniter_Rental'));
        $this->assertSame([
            'mgaq_patch_list',
            ['patch_name IN (?)' => [
                'SalesIgniter\\Rental\\Setup\\Patch\\Data\\GrantDashboardAcl',
                'SalesIgniter\\Rental\\Setup\\Patch\\Schema\\AddReferentialIntegrity',
            ]],
        ], $deleted, 'a patch class that no longer exists is forgotten too; the sibling modules are not');
    }
}

/**
 * Stands in for a revertable patch of this module: its namespace starts with SalesIgniter\Rental\.
 */
class RevertableFixturePatch implements DataPatchInterface, PatchRevertableInterface
{
    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }

    public function apply()
    {
        return $this;
    }

    public function revert()
    {
    }
}
