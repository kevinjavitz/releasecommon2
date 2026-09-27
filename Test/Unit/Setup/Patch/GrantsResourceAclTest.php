<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Setup\Patch;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Setup\Patch\GrantsResourceAcl;

class GrantsResourceAclTest extends TestCase
{
    public function testOnlyRolesAllowingSomethingOursAndWithoutARowAreGranted(): void
    {
        $rows = [
            ['role_id' => 1, 'resource_id' => 'Magento_Sales::sales', 'permission' => 'allow'],
            ['role_id' => 2, 'resource_id' => 'Magento_Sales::sales', 'permission' => 'deny'],
            ['role_id' => 3, 'resource_id' => 'Magento_Sales::sales', 'permission' => 'allow'],
            ['role_id' => 3, 'resource_id' => 'SalesIgniter_Rfq::quotes', 'permission' => 'deny'],
            ['role_id' => 4, 'resource_id' => 'SalesIgniter_Rfq::quotes', 'permission' => 'allow'],
        ];
        $granter = new class { use GrantsResourceAcl; };
        $this->assertSame([1], $granter::rolesToGrantFor($rows, 'SalesIgniter_Rfq::quotes'));
    }

    public function testTheQueryReadsTheOwnedPatternAndTheResourcesOwnRowsAndInsertsAllows(): void
    {
        $select = $this->createMock(Select::class);
        $wheres = [];
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $val) use (&$wheres, $select) {
            $wheres[] = ['where', $cond, $val];
            return $select;
        });
        $select->method('orWhere')->willReturnCallback(function ($cond, $val) use (&$wheres, $select) {
            $wheres[] = ['orWhere', $cond, $val];
            return $select;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['role_id' => 7, 'resource_id' => 'Magento_Sales::sales', 'permission' => 'allow'],
        ]);
        $inserted = null;
        $connection->method('insertMultiple')->willReturnCallback(function ($t, $rows) use (&$inserted) {
            $inserted = $rows;
            return 1;
        });
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $granter = new class {
            use GrantsResourceAcl;
            public function run($r) { return $this->grantResourceToRolesAllowing($r, 'SalesIgniter_Rfq::quotes', 'Magento_Sales::sales'); }
        };
        $this->assertSame([7], $granter->run($resource));
        $this->assertSame([
            ['where', 'resource_id LIKE ?', 'Magento_Sales::sales'],
            ['orWhere', 'resource_id = ?', 'SalesIgniter_Rfq::quotes'],
        ], $wheres);
        $this->assertSame([['role_id' => 7, 'resource_id' => 'SalesIgniter_Rfq::quotes', 'privileges' => null, 'permission' => 'allow']], $inserted);
    }
}
