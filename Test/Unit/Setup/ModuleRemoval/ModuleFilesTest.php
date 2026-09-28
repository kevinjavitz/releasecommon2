<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Setup\ModuleRemoval;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Setup\ModuleRemoval\ModuleFiles;

/**
 * Which modules depend on which, by <sequence>. A dependent never protects a table or a column,
 * so getting this wrong either leaves an add-on's column behind or drops the core module's.
 */
class ModuleFilesTest extends TestCase
{
    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/si-module-files-' . uniqid();
        $modules = [
            'SalesIgniter_Rental' => ['Magento_Catalog'],
            'SalesIgniter_Maintenance' => ['SalesIgniter_Rental'],
            'SalesIgniter_Rentalinventory' => ['SalesIgniter_Maintenance'],
            'SalesIgniter_WaiverDamage' => ['SalesIgniter_Rental'],
            'Magento_Catalog' => [],
        ];
        foreach ($modules as $name => $sequence) {
            mkdir($this->root . '/' . $name . '/etc', 0777, true);
            $children = '';
            foreach ($sequence as $dependency) {
                $children .= '<module name="' . $dependency . '"/>';
            }
            file_put_contents(
                $this->root . '/' . $name . '/etc/module.xml',
                '<config><module name="' . $name . '"><sequence>' . $children . '</sequence></module></config>'
            );
        }
        file_put_contents($this->root . '/Magento_Catalog/etc/db_schema.xml', '<schema/>');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/etc/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->root . '/*/etc') ?: [] as $dir) {
            rmdir($dir);
            rmdir(dirname($dir));
        }
        rmdir($this->root);
    }

    public function testDependentsAreFoundThroughOtherModules(): void
    {
        $files = new ModuleFiles($this->registrar());
        $dependents = $files->dependentsOf('SalesIgniter_Rental');
        sort($dependents);

        $this->assertSame(['SalesIgniter_Maintenance', 'SalesIgniter_Rentalinventory', 'SalesIgniter_WaiverDamage'], $dependents);
        $this->assertSame([], $files->dependentsOf('SalesIgniter_Rentalinventory'));
        $this->assertCount(4, $files->dependentsOf('Magento_Catalog'), 'everything above the catalog depends on it');
    }

    public function testReadOthersLeavesOutTheModuleAndTheSkipList(): void
    {
        $files = new ModuleFiles($this->registrar());
        $this->assertSame(['Magento_Catalog'], array_keys($files->readOthers('SalesIgniter_Rental', 'etc/db_schema.xml')));
        $this->assertSame([], $files->readOthers('SalesIgniter_Rental', 'etc/db_schema.xml', ['Magento_Catalog']));
        $this->assertNull($files->read('SalesIgniter_Rental', 'etc/db_schema.xml'));
        $this->assertNull($files->read('No_Such', 'etc/module.xml'));
    }

    private function registrar(): ComponentRegistrarInterface
    {
        $paths = [];
        foreach (glob($this->root . '/*') ?: [] as $dir) {
            $paths[basename($dir)] = $dir;
        }
        $registrar = $this->createStub(ComponentRegistrarInterface::class);
        $registrar->method('getPaths')->willReturnCallback(function ($type) use ($paths) {
            return $type === ComponentRegistrar::MODULE ? $paths : [];
        });
        $registrar->method('getPath')->willReturnCallback(function ($type, $name) use ($paths) {
            return $paths[$name] ?? null;
        });
        return $registrar;
    }
}
