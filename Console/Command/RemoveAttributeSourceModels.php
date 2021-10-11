<?php
/**
 * Copyright © 2017 SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */

namespace SalesIgniter\Common\Console\Command;

use Magento\Catalog\Model\Product;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Catalog\Setup\CategorySetupFactory;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Catalog\Api\ProductAttributeGroupRepositoryInterface;
use Magento\Config\Model\ResourceModel\Config\Data;
use Magento\Framework\App\ResourceConnection;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\Setup\ModuleDataSetupInterface;

class RemoveAttributeSourceModels extends Command
{


    public function __construct(
        CategorySetupFactory $categorySetupFactory,
        ProductAttributeGroupRepositoryInterface $attributeGroup,
        \Magento\Eav\Api\AttributeRepositoryInterface $attributeRepository,
        CollectionFactory $collectionFactory,
        Data $configResource,
        SchemaSetupInterface $setup,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory,
        ResourceConnection $resourceConnection,
        ModuleDataSetupInterface $eavSetup
    ) {
        $this->eavSetup = $eavSetup;
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

    protected function configure()
    {
        $this->setName('salesigniter:Remove:Attributes');
        $this->setDescription('Disables rental attribute source models when disabling module.');

        parent::configure();
    }

    /**
     *
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {


        /** @var \Magento\Catalog\Setup\CategorySetup $catalogSetup */
//        $import = $this->objectManager->create('SalesIgniter\Common\Model\Import');
//        $output->writeln("<info>test</info>");
//        $output->writeln(var_dump($import));
//        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
//        $setup = $this->objectManager->create('\Magento\Framework\Setup\SchemaSetupInterface');
//        $categorysetupfactory = $this->objectManager->create('\Magento\Catalog\Setup\CategorySetupFactory');
//        $catalogSetup = $categorysetupfactory->create(['setup' => $setup]);

        $setup = $this->eavSetup->startSetup();
//        $eavSetup = $this->eavSetupFactory->create();
        $this->_eavSetup = $this->eavSetupFactory->create( [ 'setup' => $setup ] );

        $attributes = ['sirent_use_times',
            'sirent_max',
            'sirent_min',
            'sirent_turnover_before',
            'sirent_turnover_after',
            'sirent_excluded_days',
            'sirent_future_limit',
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
            'sirent_disable_shipping',
            'sirent_padding',
            'sirent_allow_overbooking',
            'sirent_global_exclude_dates',
            'sirent_excluded_dates',
            'sirent_fixed_length',
            'sirent_enable_buyout',
            'sirent_buyout_price',
            'sirent_fixed_type',
            'sirent_hotel_mode',
            'sirent_always_show',
            'sirent_special_rules',
            'sirent_autoselectstartdate',
            'sirent_pricepoints',
            'sirent_replacepricepoints',
            'sirent_autoselectstartdate',
            'sirent_damage_waiver',
            'sirent_single_day_mode',
            'sirent_excludeddays_start',
            'sirent_excludeddays_end',
            'sirent_excludeddays_from'

        ];


        $multiTypeSettings = [
            'backend_model' => null,
            'source_model'  =>  null
        ];

        foreach ($attributes as $attribute) {
            $this->updateProductEavAttribute($attribute, $multiTypeSettings);
        }

        $output->writeln("<info>Attribute models removed</info>");

    }

    protected function updateProductEavAttribute( $AttributeCode, $Updates ) {

        foreach ( $Updates as $UpdateKey => $UpdateValue ) {
            if($this->_eavSetup->getAttributeId(\Magento\Catalog\Model\Product::ENTITY, $AttributeCode)){
                $this->updateEavAttribute(
                    Product::ENTITY,
                    $AttributeCode,
                    $UpdateKey,
                    $UpdateValue
                );
            }

        }

        return $this;
    }

    protected function updateEavAttribute( $EntityTypeId, $AttributeCode, $UpdateKey, $UpdateValue ) {
        $this->_eavSetup->updateAttribute(
            $EntityTypeId,
            $AttributeCode,
            $UpdateKey,
            $UpdateValue
        );

        return $this;
    }


}
