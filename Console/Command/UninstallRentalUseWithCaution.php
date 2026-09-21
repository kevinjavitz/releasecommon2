<?php
/**
 * Copyright © 2017 SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */

namespace SalesIgniter\Common\Console\Command;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Catalog\Setup\CategorySetupFactory;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Magento\Catalog\Api\ProductAttributeGroupRepositoryInterface;
use Magento\Config\Model\ResourceModel\Config\Data;
use Magento\Framework\App\ResourceConnection;

class UninstallRentalUseWithCaution extends Command
{

    public $attributeGroup;
    private $categorySetupfactory;
    private $eavSetupFactory;
    private $collectionFactory;
    private $configResource;

    public function __construct(
        CategorySetupFactory $categorySetupFactory,
        ProductAttributeGroupRepositoryInterface $attributeGroup,
        \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository,
        CollectionFactory $collectionFactory,
        Data $configResource,
        SchemaSetupInterface $setup,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory,
        ResourceConnection $resourceConnection
    ) {
        $this->attributeGroup = $attributeGroup;
        $this->categorySetupfactory = $categorySetupFactory;
        $this->eavSetupFactory = $eavSetupFactory;
        $this->attributeRepository = $attributeRepository;
        $this->objectManager = $objectManager;
        $this->resourceConnection = $resourceConnection;
        $this->collectionFactory = $collectionFactory;
        $this->configResource    = $configResource;
        $this->setup = $setup;
        parent::__construct();
    }

    private $categorySetupFactory;

    private $setup;

    private $resourceConnection;

    private $objectManager;

    private $attributeRepository;

    private $attributeSet;

    /**
     * Option that lets a script skip the confirmation prompt.
     */
    public const OPTION_FORCE = 'force';

    protected function configure()
    {
        $this->setName('salesigniter:Uninstall');
        $this->setDescription('Caution: Deletes all Sales Igniter Rental product attributes, tables, reservation history.');
        $this->addOption(
            self::OPTION_FORCE,
            'f',
            InputOption::VALUE_NONE,
            'Delete everything without asking. Required when the command is not run interactively.'
        );

        parent::configure();
    }

    /**
     * Ask before destroying anything.
     *
     * This command drops every sirental_* table, including all reservation history, removes
     * 71 product attributes and deletes the rental store configuration. None of it is
     * recoverable and none of it used to be confirmed: `bin/magento salesigniter:Uninstall`
     * on the wrong terminal was enough. So: a prompt when there is a human to ask, and a
     * refusal when there is not, unless --force says otherwise.
     *
     * @return bool True when the caller has confirmed and the command may proceed.
     */
    private function isConfirmed(InputInterface $input, OutputInterface $output): bool
    {
        if ($input->getOption(self::OPTION_FORCE)) {
            return true;
        }

        $output->writeln(
            '<error>This permanently deletes every Sales Igniter rental product attribute, every'
            . ' sirental_* table (all reservation, serial number and payment history) and the'
            . ' rental store configuration. It cannot be undone.</error>'
        );

        if (!$input->isInteractive()) {
            $output->writeln(
                '<error>Refusing to run without confirmation. Re-run with --force if that is'
                . ' really what you want.</error>'
            );

            return false;
        }

        $helperSet = $this->getHelperSet();
        $question = ($helperSet !== null && $helperSet->has('question'))
            ? $this->getHelper('question')
            : new QuestionHelper();

        return (bool)$question->ask(
            $input,
            $output,
            new ConfirmationQuestion('Type "yes" to delete it all: ', false, '/^yes$/i')
        );
    }

    /**
     *
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->isConfirmed($input, $output)) {
            $output->writeln('<info>Aborted. Nothing was deleted.</info>');

            return \Magento\Framework\Console\Cli::RETURN_FAILURE;
        }

        /** @var \Magento\Catalog\Setup\CategorySetup $catalogSetup */
//        $import = $this->objectManager->create('SalesIgniter\Common\Model\Import');
//        $output->writeln("<info>test</info>");
//        $output->writeln(var_dump($import));
//        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
//        $setup = $this->objectManager->create('\Magento\Framework\Setup\SchemaSetupInterface');
//        $categorysetupfactory = $this->objectManager->create('\Magento\Catalog\Setup\CategorySetupFactory');
//        $catalogSetup = $categorysetupfactory->create(['setup' => $setup]);

        $attributes = ['sirent_use_times',
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

        $eavSetup = $this->eavSetupFactory->create();

        foreach ($attributes as $attribute) {
            $output->writeln("<info>working on $attribute</info>");
            $eavSetup->removeAttribute(\Magento\Catalog\Model\Product::ENTITY, $attribute);
            $output->writeln("<info>$attribute table deleted</info>");
        }


//        foreach ($attributes as $attribute) {
//            $output->writeln("<info>working on $attribute</info>");
//            $attributeData = $this->attributeRepository->get(\Magento\Catalog\Model\Product::ENTITY, $attribute);
//            if($attributeData->getAttributeId()) {
//                try {
//                    $this->attributeRepository->delete($attributeData);
//                } catch (\Exception $e){
//                    $output->writeln("<info>$attribute can't be deleted</info>");
//                    $message = $e->getMessage();
//                    $output->writeln("<info>$message</info>");
//                }
//            }
//
//        }

        $tables = ['sirental_fixed_dates',
            'sirental_fixed_names',
            'sirental_inventory_grid',
            'sirental_payment_transaction',
            'sirental_price',
            'sirental_reservationorders',
            'sirental_return',
            'sirental_serialnumber_details'];

        $setup = $this->setup->startSetup();

        foreach ($tables as $table) {
            $setup->getConnection()->dropTable($table);
            $output->writeln("<info>$table table deleted</info>");
        }

        // attribute set id: 4
        // attribute group code: rental
        // attribute group name: Rental
        // entity type id \Magento\Catalog\Model\Product::ENTITY or 'catalog_product'
        $entityTypeId = $eavSetup->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY);
        $attributeSetId = $eavSetup->getDefaultAttributeSetId($entityTypeId);

        $attributeGroupId = $eavSetup->getAttributeGroupId($entityTypeId, $attributeSetId, 'Rental');
        $output->writeln("<info>Attribute Set Id is: $attributeSetId</info>");
        $output->writeln("<info>Attribute Group Id is: $attributeGroupId</info>");
//        $attributeGroup = $eavSetup->getAttributeGroup($entityTypeId, $attributeSetId, );

//        $output->writeln("<info>Entity Set Id is</info>");

        $eavSetup->removeAttributeGroup($entityTypeId, $attributeSetId, $attributeGroupId);
        $output->writeln("<info>Rental attribute group is removed</info>");
//        $attributeSetId = $this->attributeSet->getAttributeSetId();
//        $catalogSetup->addAttributeGroup($entityTypeId, $attributeSetId, 'Rental', 60);
//        $output->writeln("<info>Rental attribute set deleted</info>");

//        $collection = $this->collectionFactory->create()
//            ->addPathFilter('section_id');
//        foreach ($collection as $config) {
//            $this->deleteConfig($config);
//        }
        $connection  = $this->resourceConnection->getConnection();
        $tablecoreconfig = $connection->getTableName('core_config_data');
        $query = "DELETE FROM $tablecoreconfig WHERE path LIKE 'salesigniter_rental/%';";
        $connection->query($query);
        $output->writeln("<info>Core Config Values Deleted</info>");

        $tablesetup = $connection->getTableName('setup_module');
        $query2 = "DELETE FROM $tablesetup WHERE module LIKE 'SalesIgniter_Rental'";
        $query3 = "DELETE FROM $tablesetup WHERE module LIKE 'SalesIgniter_Common'";
        $connection->query($query2);
        $connection->query($query3);
        $output->writeln("<info>setup_module Value Deleted</info>");
        $output->writeln("<info>Uninstall complete</info>");

        return 0;

    }


}
