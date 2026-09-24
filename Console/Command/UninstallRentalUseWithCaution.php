<?php
/**
 * Copyright © 2017 SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */

namespace SalesIgniter\Common\Console\Command;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\Module\FullModuleList;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento salesigniter:Uninstall` - retired in 1.2.56. It deletes nothing any more.
 *
 * It used to remove the rental attributes, configuration and setup_module rows itself, and drop a
 * hard-coded list of tables. That list was stale (it missed most of the tables 1.2.197 onwards
 * declare), it passed bare table names, so on a database with a table prefix it dropped no tables
 * at all while still deleting everything else, and it dropped sirental_serialnumber_details with
 * foreign-key checks off under tables that still pointed at it.
 *
 * Uninstalling is now Magento's own `bin/magento module:uninstall --remove-data`, which calls each
 * module's Setup\Uninstall (releaserental2 >= 1.2.206 and its add-ons). This command prints that
 * command, with the modules this store actually has, in the order they must be given, and exits
 * non-zero so a script that still calls it stops instead of carrying on as if it had worked.
 */
class UninstallRentalUseWithCaution extends Command
{
    /** Kept so an old script's `--force` reaches the explanation instead of a Symfony error. */
    public const OPTION_FORCE = 'force';

    public const DOCS_URL = 'https://rentalbookingsoftware.com/docs/uninstall-or-disable/';

    /**
     * The modules that make up the core extension, removed last and in this order: the add-ons
     * depend on them, SalesIgniter_Rental and SalesIgniter_Bookingsgrid require each other, and
     * SalesIgniter_Rental requires SalesIgniter_Common.
     */
    public const CORE_MODULES = ['SalesIgniter_Bookingsgrid', 'SalesIgniter_Rental', 'SalesIgniter_Common'];

    /** @var FullModuleList */
    private $fullModuleList;

    /** @var ComponentRegistrarInterface */
    private $componentRegistrar;

    /**
     * @param FullModuleList $fullModuleList
     * @param ComponentRegistrarInterface $componentRegistrar
     */
    public function __construct(FullModuleList $fullModuleList, ComponentRegistrarInterface $componentRegistrar)
    {
        $this->fullModuleList = $fullModuleList;
        $this->componentRegistrar = $componentRegistrar;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('salesigniter:Uninstall');
        $this->setDescription(
            'Retired: prints how to remove the Sales Igniter rental extension with bin/magento module:uninstall. Deletes nothing.'
        );
        $this->addOption(self::OPTION_FORCE, 'f', InputOption::VALUE_NONE, 'Ignored; the command deletes nothing.');

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int always Cli::RETURN_FAILURE
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $modules = $this->uninstallOrder();
        $byHand = array_values(array_filter($modules, [$this, 'isInAppCode']));
        $modules = array_values(array_diff($modules, $byHand));

        $output->writeln('<error>salesigniter:Uninstall has been retired. Nothing was deleted.</error>');
        $output->writeln('');
        $output->writeln('Remove the extension with Magento\'s own command. It removes each module\'s tables,');
        $output->writeln('columns, product attributes and settings, converts rental products to simple or');
        $output->writeln('virtual products (disabled), keeps every order, and then runs composer remove.');
        $output->writeln('Take a database backup first. Name every module in ONE command, in this order:');
        $output->writeln('');
        $output->writeln('  bin/magento maintenance:enable');
        $output->writeln('  bin/magento module:uninstall --remove-data ' . implode(' ', $modules));
        $output->writeln('  bin/magento setup:upgrade');
        $output->writeln('  bin/magento setup:di:compile');
        $output->writeln('  bin/magento setup:static-content:deploy   (production mode only)');
        $output->writeln('  bin/magento indexer:reindex');
        $output->writeln('  bin/magento cache:flush');
        $output->writeln('  bin/magento maintenance:disable');
        if ($byHand) {
            $output->writeln('');
            $output->writeln('Not Composer packages, so module:uninstall cannot remove them; delete their app/code');
            $output->writeln('folders by hand first: ' . implode(' ', $byHand));
        }
        $output->writeln('');
        $output->writeln('To switch the extension off without deleting anything, disable it instead.');
        $output->writeln('Both procedures: ' . self::DOCS_URL);

        return Cli::RETURN_FAILURE;
    }

    /**
     * The Sales Igniter modules this install has, dependents first and the core modules last.
     *
     * FullModuleList is sorted by <sequence> (a module after the modules it depends on), so its
     * reverse puts every add-on before what it depends on.
     *
     * @return string[]
     */
    public function uninstallOrder(): array
    {
        $installed = array_values(array_filter(
            $this->fullModuleList->getNames(),
            static function ($name) {
                return strpos($name, 'SalesIgniter_') === 0 || $name === 'Hyva_SalesIgniterRental';
            }
        ));
        $addOns = array_reverse(array_values(array_diff($installed, self::CORE_MODULES)));
        $core = array_values(array_intersect(self::CORE_MODULES, $installed));

        return array_merge($addOns, $core);
    }

    /**
     * @param string $moduleName
     * @return bool
     */
    private function isInAppCode(string $moduleName): bool
    {
        $path = (string)$this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
        return strpos(str_replace('\\', '/', $path), '/app/code/') !== false;
    }
}
