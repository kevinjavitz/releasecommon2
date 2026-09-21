<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Console\Command\CreateProductsCommand;
use Symfony\Component\Console\Command\Command;

/**
 * The things about this module that other things depend on, and that nothing else checks.
 *
 * Everything here is a fact about files rather than about behaviour, which is the point: the
 * facts are what break other modules, and a wrong one is invisible until an install fatals.
 */
class ModuleContractTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../..';

    /* --------------------------------------------- the Symfony Console 7 floor */

    /**
     * This is the whole reason 1.2.54 exists.
     *
     * Magento 2.4.9 pulls symfony/console 7, where Command::execute() is declared `: int`. A
     * subclass without that return type is a fatal at CLASS LOAD, and because Magento
     * constructs every command registered in a CommandListInterface, one such class kills the
     * entire `bin/magento` binary - including setup:upgrade, so you cannot even finish the
     * upgrade that caused it.
     *
     * The project CLAUDE.md records "releasecommon2 >= 1.2.54" as a hard floor for 2.4.9. This
     * test is that floor written down in code: every Command subclass in this module declares
     * the return type.
     *
     */
    #[DataProvider('commandClasses')]
    public function testEveryCommandDeclaresTheIntReturnTypeSymfonySevenRequires(string $class): void
    {
        $reflection = new \ReflectionClass($class);
        if (!$reflection->hasMethod('execute')) {
            // A class with no execute() of its own inherits Symfony's, which already has the
            // return type. CreateProductsCommand is the one in this module; see below.
            $this->assertTrue(
                $reflection->isSubclassOf(Command::class),
                $class . ' is in Console/Command but is not a Symfony command'
            );
            return;
        }

        $execute = $reflection->getMethod('execute');
        $returnType = $execute->getReturnType();

        $this->assertInstanceOf(
            \ReflectionNamedType::class,
            $returnType,
            $class . '::execute() has no return type; symfony/console 7 makes that a fatal at class load'
        );
        $this->assertSame('int', $returnType->getName(), $class . '::execute() must return int');
        $this->assertFalse($returnType->allowsNull(), $class . '::execute() must not be nullable');
    }

    public static function commandClasses(): array
    {
        $cases = [];
        foreach (glob(self::MODULE_ROOT . '/Console/Command/*.php') as $file) {
            $short = basename($file, '.php');
            $cases[$short] = ['SalesIgniter\Common\Console\Command\\' . $short];
        }
        self::assertNotEmpty($cases);

        return $cases;
    }

    public function testTheModuleStillShipsTheSixCommandClassesTheNotesDescribe(): void
    {
        $this->assertSame(
            [
                'CreateProductsCommand',
                'ImportCommand',
                'RemoveAttributeSourceModels',
                'RestoreAttributeSourceModels',
                'RunTestCommand',
                'UninstallRentalUseWithCaution',
            ],
            array_map(
                static fn (string $f): string => basename($f, '.php'),
                glob(self::MODULE_ROOT . '/Console/Command/*.php')
            )
        );
    }

    /* ------------------------------------------------- CreateProductsCommand */

    /**
     * CreateProductsCommand looks like a command and is not one.
     *
     * It has no configure() and no execute(). It is a bag of product-fixture builders that
     * RunTestCommand extends. The module notes call this out, and the reason it matters is
     * that Symfony's Command::execute() THROWS - so if anyone ever adds it to the di.xml
     * command list, `bin/magento` gains an unnamed command and running it explodes.
     *
     * Both halves of that are pinned here so the class is not "fixed" into a real command by
     * accident, and not registered by accident either.
     */
    public function testCreateProductsCommandDefinesNeitherConfigureNorExecute(): void
    {
        $reflection = new \ReflectionClass(CreateProductsCommand::class);

        $this->assertTrue($reflection->isSubclassOf(Command::class));
        $this->assertFalse(
            $reflection->hasMethod('execute') && $reflection->getMethod('execute')->getDeclaringClass()->getName() === CreateProductsCommand::class,
            'CreateProductsCommand is a base class for fixtures; it must not grow an execute()'
        );
        $this->assertFalse(
            $reflection->hasMethod('configure') && $reflection->getMethod('configure')->getDeclaringClass()->getName() === CreateProductsCommand::class,
            'CreateProductsCommand has no name, so it must not grow a configure() either'
        );
    }

    public function testSymfonysInheritedExecuteThrowsWhichIsWhyItMustStayUnregistered(): void
    {
        $command = new \ReflectionClass(CreateProductsCommand::class);
        $execute = $command->getMethod('execute');

        $this->assertSame(
            Command::class,
            $execute->getDeclaringClass()->getName(),
            'the execute() it has is Symfony\'s, which throws LogicException when called'
        );
    }

    /* ----------------------------------------------------- di.xml registration */

    /**
     * Three commands registered, RunTestCommand commented out, CreateProductsCommand absent.
     *
     */
    #[DataProvider('registeredCommands')]
    public function testDiXmlRegistersTheExpectedCommands(string $itemName, string $class): void
    {
        $commands = $this->registeredCommandMap();

        $this->assertArrayHasKey($itemName, $commands);
        $this->assertSame($class, $commands[$itemName]);
    }

    public static function registeredCommands(): array
    {
        return [
            ['uninstallRental', 'SalesIgniter\Common\Console\Command\UninstallRentalUseWithCaution'],
            ['removeAttributes', 'SalesIgniter\Common\Console\Command\RemoveAttributeSourceModels'],
            ['restoreAttributes', 'SalesIgniter\Common\Console\Command\RestoreAttributeSourceModels'],
        ];
    }

    public function testDiXmlRegistersNothingElse(): void
    {
        $this->assertSame(
            ['uninstallRental', 'removeAttributes', 'restoreAttributes'],
            array_keys($this->registeredCommandMap()),
            'RunTestCommand stays commented out and CreateProductsCommand stays unregistered'
        );
    }

    /* ---------------------------------------------------------------- version */

    /**
     * composer.json `version` and etc/module.xml `setup_version` must agree - the release
     * checklist in the project CLAUDE.md says to bump both, and a mismatch means an install
     * that composer thinks is upgraded and Magento thinks is not.
     */
    public function testComposerVersionAndSetupVersionAgree(): void
    {
        $composer = json_decode(file_get_contents(self::MODULE_ROOT . '/composer.json'), true);
        $module = simplexml_load_file(self::MODULE_ROOT . '/etc/module.xml');
        $setupVersion = (string)$module->module['setup_version'];

        $this->assertSame($composer['version'], $setupVersion);
        $this->assertSame('SalesIgniter_Common', (string)$module->module['name']);
    }

    public function testTheVersionIsAtOrAboveTheMagentoTwoFourNineFloor(): void
    {
        $composer = json_decode(file_get_contents(self::MODULE_ROOT . '/composer.json'), true);

        $this->assertGreaterThanOrEqual(
            0,
            version_compare($composer['version'], '1.2.54'),
            'below 1.2.54 every bin/magento call fatals under Symfony Console 7 on Magento 2.4.9'
        );
    }

    /* ---------------------------------------------------------------- helpers */

    /**
     * @return array<string,string> item name => class, in document order
     */
    private function registeredCommandMap(): array
    {
        $xml = simplexml_load_file(self::MODULE_ROOT . '/etc/di.xml');
        $this->assertNotFalse($xml, 'etc/di.xml does not parse');

        $map = [];
        foreach ($xml->xpath('//type[@name="Magento\Framework\Console\CommandListInterface"]/arguments/argument[@name="commands"]/item') as $item) {
            $map[(string)$item['name']] = trim((string)$item);
        }

        return $map;
    }
}
