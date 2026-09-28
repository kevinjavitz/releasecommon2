<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Setup\ModuleRemoval;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SalesIgniter\Common\Setup\ModuleRemoval\DbSchemaReader;
use SalesIgniter\Common\Setup\ModuleRemoval\ModuleFiles;
use SalesIgniter\Common\Setup\ModuleRemoval\SchemaRemover;

/**
 * What the uninstall drops, and in what order - including the two bugs the old
 * salesigniter:Uninstall had: bare table names on a prefixed database, and a parent table dropped
 * with FOREIGN_KEY_CHECKS off under a child that still pointed at it.
 */
class SchemaRemoverTest extends TestCase
{
    private const MINE = <<<XML
<schema xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <table name="si_parent">
    <column xsi:type="int" name="id"/>
    <constraint xsi:type="primary" referenceId="PRIMARY"><column name="id"/></constraint>
  </table>
  <table name="si_child">
    <column xsi:type="int" name="id"/><column xsi:type="int" name="parent_id"/>
    <constraint xsi:type="primary" referenceId="PRIMARY"><column name="id"/></constraint>
    <constraint xsi:type="foreign" referenceId="FK" table="si_child" column="parent_id" referenceTable="si_parent" referenceColumn="id"/>
  </table>
  <table name="quote">
    <column xsi:type="boolean" name="is_reserved"/>
    <column xsi:type="int" name="customer_id"/>
  </table>
  <table name="si_shared">
    <column xsi:type="int" name="id"/>
    <constraint xsi:type="primary" referenceId="PRIMARY"><column name="id"/></constraint>
  </table>
  <table name="si_extended">
    <column xsi:type="int" name="shared_col"/><column xsi:type="int" name="mine_only"/>
  </table>
</schema>
XML;

    private const CORE = <<<XML
<schema xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <table name="quote">
    <column xsi:type="int" name="entity_id"/><column xsi:type="int" name="customer_id"/>
    <constraint xsi:type="primary" referenceId="PRIMARY"><column name="entity_id"/></constraint>
  </table>
  <table name="core_owned_old"><column xsi:type="int" name="id"/></table>
</schema>
XML;

    /** A module that does not depend on ours and declares one of our tables and one of our columns. */
    private const EXTENDER = <<<XML
<schema xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <table name="si_shared"><column xsi:type="int" name="extra"/></table>
  <table name="si_extended"><column xsi:type="int" name="shared_col"/></table>
</schema>
XML;

    /** @var string[] */
    private $calls = [];

    public function testThePlanDropsOwnTablesAndOnlyOwnColumnsOfOthersTables(): void
    {
        $plan = $this->remover()->plan(
            'SalesIgniter_Test',
            ['si_legacy', 'core_owned_old'],
            ['sales_order' => ['signature_text']]
        );

        $this->assertSame(['si_parent', 'si_child', 'si_legacy'], $plan['tables'], 'si_shared is declared elsewhere too, so kept');
        $this->assertSame([
            'quote' => ['is_reserved'],
            'si_extended' => ['mine_only'],
            'sales_order' => ['signature_text'],
        ], $plan['columns'], 'customer_id is Magento\'s and shared_col is Vendor_Extends\'s');
    }

    public function testEveryNameIsPrefixedAndConstraintsGoBeforeTables(): void
    {
        $setup = $this->schemaSetup([]);
        $this->remover()->remove($setup, 'SalesIgniter_Test', ['si_legacy']);

        $this->assertSame([
            'dropForeignKey mgaq_si_child MGAQ_FK',
            'dropTable mgaq_si_parent',
            'dropTable mgaq_si_child',
            'dropTable mgaq_si_legacy',
            'dropColumn mgaq_quote is_reserved',
        ], $this->calls);
    }

    public function testAForeignKeyFromSomebodyElsesTableStopsEverythingFirst(): void
    {
        $setup = $this->schemaSetup([
            ['TABLE_NAME' => 'mgaq_other_table', 'CONSTRAINT_NAME' => 'FK_X', 'REFERENCED_TABLE_NAME' => 'mgaq_si_parent'],
        ]);
        try {
            $this->remover()->remove($setup, 'SalesIgniter_Test');
            $this->fail('expected a refusal');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('mgaq_other_table.FK_X -> mgaq_si_parent', $e->getMessage());
        }
        $this->assertSame([], $this->calls, 'nothing dropped');
    }

    public function testAModuleWithoutDbSchemaDropsNothing(): void
    {
        $files = $this->createStub(ModuleFiles::class);
        $files->method('read')->willReturn(null);
        $files->method('readOthers')->willReturn([]);
        $files->method('dependentsOf')->willReturn([]);
        $remover = new SchemaRemover($files, new DbSchemaReader(), new NullLogger());

        $this->assertSame(['tables' => [], 'columns' => []], $remover->plan('SalesIgniter_None'));
    }

    private function remover(): SchemaRemover
    {
        $files = $this->createStub(ModuleFiles::class);
        $files->method('read')->willReturnCallback(function ($module, $path) {
            return $path === 'etc/db_schema.xml' ? self::MINE : null;
        });
        $files->method('dependentsOf')->willReturn(['SalesIgniter_Dependent']);
        $files->method('readOthers')->willReturnCallback(function ($except, $path, $skip) {
            $this->assertSame(['SalesIgniter_Dependent'], $skip, 'a module that depends on this one never protects anything');
            return ['Magento_Quote' => self::CORE, 'Vendor_Extends' => self::EXTENDER];
        });
        return new SchemaRemover($files, new DbSchemaReader(), new NullLogger());
    }

    /**
     * @param array $incoming rows the information_schema query answers
     * @return SchemaSetupInterface
     */
    private function schemaSetup(array $incoming): SchemaSetupInterface
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'distinct'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $existing = ['mgaq_si_parent', 'mgaq_si_child', 'mgaq_si_legacy', 'mgaq_quote', 'mgaq_si_shared'];

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($incoming);
        $connection->method('isTableExists')->willReturnCallback(function ($table) use ($existing) {
            return in_array($table, $existing, true);
        });
        $connection->method('tableColumnExists')->willReturn(true);
        $connection->method('getForeignKeys')->willReturnCallback(function ($table) {
            return $table === 'mgaq_si_child' ? ['MGAQ_FK' => ['FK_NAME' => 'MGAQ_FK']] : [];
        });
        $connection->method('dropForeignKey')->willReturnCallback(function ($table, $name) use ($connection) {
            $this->calls[] = "dropForeignKey $table $name";
            return $connection;
        });
        $connection->method('dropTable')->willReturnCallback(function ($table) {
            $this->calls[] = "dropTable $table";
            return true;
        });
        $connection->method('dropColumn')->willReturnCallback(function ($table, $column) {
            $this->calls[] = "dropColumn $table $column";
            return true;
        });

        $setup = $this->createStub(SchemaSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(function ($table) {
            return 'mgaq_' . $table;
        });
        return $setup;
    }
}
