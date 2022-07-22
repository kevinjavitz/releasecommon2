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

class RestoreAttributeSourceModels extends Command
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
        $this->setName('salesigniter:Restore:Attributes');
        $this->setDescription('Restores rental attribute source models when enabling module.');

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
        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\SirentBackendConfig',
            'source_model'  =>  ''
        ];
        $attributes = [
            'sirent_hotel_mode',
            'sirent_always_show',
            'sirent_damage_waiver',
            'sirent_autoselectstartdate',
            'sirent_pricepoints',
            'sirent_padding',
            'sirent_min',
            'sirent_turnover_before',
            'sirent_turnover_after',
            'sirent_future_limit',
            'sirent_allow_overbooking',
            'sirent_fixed_length',
            'sirent_disable_shipping',
            'sirent_single_day_mode',
            'sirent_excluded_dates'
        ];

        foreach ($attributes as $attribute) {
            $this->updateProductEavAttribute($attribute, $multiTypeSettings);
        }

        $attribute = 'sirent_excluded_days';
        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\Multiselect',
            'source_model'  =>  'SalesIgniter\Rental\Model\Attribute\Sources\ExcludedDaysWeek'
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_excludeddays_start';
        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\Multiselect',
            'source_model'  =>  'SalesIgniter\Rental\Model\Attribute\Sources\ExcludedDaysWeek'
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_excludeddays_end';
        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\Multiselect',
            'source_model'  =>  'SalesIgniter\Rental\Model\Attribute\Sources\ExcludedDaysWeek'
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_excludeddays_from';
        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\Multiselect',
            'source_model'  =>  'SalesIgniter\Rental\Model\Attribute\Sources\ExcludedDaysWeekFrom'
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_special_rules';
        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\Multiselect',
            'source_model'  =>  'SalesIgniter\Rental\Model\Attribute\Sources\SpecialPricingRules'
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_price';
        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\RentalPrice',
            'source_model'  =>  ''
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_serial_numbers';
        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\SerialNumbers',
            'source_model'  =>  ''
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);


        $attribute = 'sirent_fixed_type';
        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\SirentBackendConfig',
            'source_model'  =>  'SalesIgniter\Rental\Model\Attribute\Sources\FixedType'
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_pricingtype';
        $multiTypeSettings = [
            'backend_model' => '',
            'source_model'  =>  'SalesIgniter\Rental\Model\Attribute\Sources\PricingType'
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_rental_type';
        $multiTypeSettings = [
            'backend_model' => '',
            'source_model'  =>  'SalesIgniter\Rental\Model\Attribute\Sources\RentalType'
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_bundle_price_type';
        $multiTypeSettings = [
            'backend_model' => '',
            'source_model'  =>  'SalesIgniter\Rental\Model\Attribute\Sources\BundlePriceType'
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_excluded_dates';
        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\ExcludedDates',
            'source_model'  =>  ''
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $attribute = 'sirent_use_times';
        $multiTypeSettings = [
            'backend_model' => '',
            'source_model'  =>  'SalesIgniter\Rental\Model\Attribute\Sources\UseTimes'
        ];
        $this->updateProductEavAttribute($attribute, $multiTypeSettings);

        $multiTypeSettings = [
            'backend_model' => 'SalesIgniter\Rental\Model\Attribute\Backend\Time',
            'source_model'  =>  ''
        ];

        $attributes = ['sirent_hour_next_day',
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
            'sirent_store_open_sunday'];

        foreach ($attributes as $attribute) {
            $this->updateProductEavAttribute($attribute, $multiTypeSettings);
        }

        $output->writeln("<info>Attribute models restored</info>");

    }

    protected function updateProductEavAttribute( $AttributeCode, $Updates ) {

        foreach ( $Updates as $UpdateKey => $UpdateValue ) {
            if($this->_eavSetup->getAttributeId(\Magento\Catalog\Model\Product::ENTITY, $AttributeCode)) {
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
