<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Setup\ModuleRemoval;

use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Setup\ModuleRemoval\DbSchemaReader;

/**
 * The module's real db_schema.xml, read the way the uninstall reads it: every sirental_* table is
 * one the module creates (it declares the primary key), and the Magento tables it touches are
 * extended, column by column.
 */
class DbSchemaReaderTest extends TestCase
{
    /**
     * These cases read the rental module's real files (the engine moved here from releaserental2 in
     * 1.2.57); they are skipped where that module is not installed.
     */
    private static function rentalRoot(): string
    {
        $path = (new \Magento\Framework\Component\ComponentRegistrar())->getPath(
            \Magento\Framework\Component\ComponentRegistrar::MODULE,
            'SalesIgniter_Rental'
        );
        if (!$path) {
            self::markTestSkipped('SalesIgniter_Rental is not installed');
        }
        return $path;
    }

    public function testEverySirentalTableIsCreatedHereAndEveryMagentoTableIsOnlyExtended(): void
    {
        $tables = (new DbSchemaReader())->read((string)file_get_contents(self::rentalRoot() . '/etc/db_schema.xml'));

        foreach ($tables as $name => $table) {
            $this->assertSame(strpos($name, 'sirental_') === 0, $table['primary'], $name);
        }
        $this->assertArrayHasKey('sirental_serialnumber_details', $tables);
        $this->assertArrayHasKey('sirental_serial_photos', $tables);
        $this->assertSame(['is_reserved', 'skip_rental_stock_check'], $tables['quote']['columns']);
        $this->assertSame(['is_reserved'], $tables['quote_item']['columns']);
        $this->assertSame(['pickup_date', 'dropoff_date', 'is_reserved', 'manually_reserved'], $tables['sales_order']['columns']);
        $this->assertSame(['reservationorder_id', 'start_date', 'end_date'], $tables['sales_shipment_item']['columns']);
        $this->assertSame(['reservationorder_id', 'start_date', 'end_date'], $tables['sales_creditmemo_item']['columns']);
    }

    public function testTheSerialTablesForeignKeysAreRead(): void
    {
        $tables = (new DbSchemaReader())->read((string)file_get_contents(self::rentalRoot() . '/etc/db_schema.xml'));
        $targets = array_column($tables['sirental_readings']['foreign'], 'referenceTable');
        $this->assertContains('sirental_serialnumber_details', $targets);
    }

    public function testATableDeclaredTwiceInOneFileIsMerged(): void
    {
        $xml = <<<XML
<schema xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <table name="t"><column xsi:type="int" name="a"/></table>
  <table name="t"><column xsi:type="int" name="b"/><constraint xsi:type="primary" referenceId="PRIMARY"><column name="a"/></constraint></table>
</schema>
XML;
        $tables = (new DbSchemaReader())->read($xml);
        $this->assertSame(['a', 'b'], $tables['t']['columns']);
        $this->assertTrue($tables['t']['primary']);
    }

    public function testUnparseableXmlIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DbSchemaReader())->read('<schema><table');
    }
}
