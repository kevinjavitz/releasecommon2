<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Console\Command;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Module\FullModuleList;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Console\Command\UninstallRentalUseWithCaution;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bin/magento salesigniter:Uninstall`, retired in 1.2.56.
 *
 * It used to delete the rental attributes, settings and setup_module rows and drop a stale list
 * of tables (by bare name, so nothing at all on a prefixed database). Now it deletes nothing: it
 * prints the `module:uninstall --remove-data` command for the modules this store has, in the
 * order Magento needs them, and fails, so a script still calling it stops.
 */
class UninstallRentalUseWithCautionTest extends TestCase
{
    /** In FullModuleList order: every module after the modules it depends on. */
    private const INSTALLED = [
        'Magento_Catalog',
        'SalesIgniter_Common',
        'SalesIgniter_Rental',
        'SalesIgniter_Bookingsgrid',
        'SalesIgniter_Maintenance',
        'SalesIgniter_Rentalinventory',
        'SalesIgniter_WaiverDamage',
        'Hyva_SalesIgniterRental',
        'SalesIgniter_DemoCacheFlush',
        'Vendor_Other',
    ];

    public function testTheConstructorAsksForNothingThatTouchesTheDatabase(): void
    {
        $types = [];
        foreach ((new \ReflectionClass(UninstallRentalUseWithCaution::class))->getConstructor()->getParameters() as $parameter) {
            $types[] = $parameter->getType()->getName();
        }
        $this->assertSame([FullModuleList::class, ComponentRegistrarInterface::class], $types);
    }

    public function testAddOnsComeFirstAndTheCoreModulesLastInDependencyOrder(): void
    {
        $this->assertSame([
            'SalesIgniter_DemoCacheFlush',
            'Hyva_SalesIgniterRental',
            'SalesIgniter_WaiverDamage',
            'SalesIgniter_Rentalinventory',
            'SalesIgniter_Maintenance',
            'SalesIgniter_Bookingsgrid',
            'SalesIgniter_Rental',
            'SalesIgniter_Common',
        ], $this->command()->uninstallOrder());
    }

    /**
     * @dataProvider invocations
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invocations')]
    public function testItPrintsTheModuleUninstallCommandAndFails(array $input, bool $interactive): void
    {
        $tester = new CommandTester($this->command());
        $status = $tester->execute($input, ['interactive' => $interactive]);
        $display = $tester->getDisplay();

        $this->assertSame(1, $status, 'a script that still calls it must stop');
        $this->assertStringContainsString('Nothing was deleted', $display);
        $this->assertStringContainsString(
            'bin/magento module:uninstall --remove-data Hyva_SalesIgniterRental SalesIgniter_WaiverDamage '
            . 'SalesIgniter_Rentalinventory SalesIgniter_Maintenance SalesIgniter_Bookingsgrid SalesIgniter_Rental '
            . 'SalesIgniter_Common',
            $display
        );
        $this->assertStringContainsString('delete their app/code', $display);
        $this->assertStringContainsString('SalesIgniter_DemoCacheFlush', $display);
        $this->assertStringContainsString(UninstallRentalUseWithCaution::DOCS_URL, $display);
    }

    public static function invocations(): array
    {
        return [
            'interactive' => [[], true],
            'from a script' => [[], false],
            'with the old --force' => [['--force' => true], false],
        ];
    }

    public function testTheCommandIsNamedAndDescribedAsRetired(): void
    {
        $command = $this->command();
        $this->assertSame('salesigniter:Uninstall', $command->getName());
        $this->assertStringContainsString('Retired', $command->getDescription());
        $this->assertStringContainsString('Deletes nothing', $command->getDescription());
        $this->assertTrue($command->getDefinition()->hasOption('force'));
    }

    private function command(): UninstallRentalUseWithCaution
    {
        $modules = $this->createStub(FullModuleList::class);
        $modules->method('getNames')->willReturn(self::INSTALLED);
        $registrar = $this->createStub(ComponentRegistrarInterface::class);
        $registrar->method('getPath')->willReturnCallback(function ($type, $name) {
            return $type === ComponentRegistrar::MODULE && $name === 'SalesIgniter_DemoCacheFlush'
                ? '/var/www/html/app/code/SalesIgniter/DemoCacheFlush'
                : '/var/www/html/vendor/salesigniter/' . strtolower($name);
        });
        return new UninstallRentalUseWithCaution($modules, $registrar);
    }
}
