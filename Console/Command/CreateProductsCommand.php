<?php
/**
 * Copyright © 2017 SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */

namespace SalesIgniter\Common\Console\Command;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Setup\CategorySetup;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Model\Customer;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Class CreateProductsCommand.
 *
 * @author   SalesIgniter <contact@rentalbookingsoftware.com>
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.LineMaxExceeded)
 */
class CreateProductsCommand extends Command
{
    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    protected $productRepository;
    /**
     * @var \Magento\Catalog\Model\Product\Action
     */
    protected $attributeAction;
    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $productFactory;

    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var \Magento\Quote\Model\QuoteManagement
     */
    protected $quoteManagement;
    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $customerFactory;
    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepository;
    /**
     * @var \Magento\Sales\Model\Service\OrderService
     */
    protected $orderService;
    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $cartRepositoryInterface;
    /**
     * @var \Magento\Quote\Api\CartManagementInterface
     */
    protected $cartManagementInterface;
    /**
     * @var \Magento\Quote\Model\Quote\Address\Rate
     */
    protected $shippingRate;

    /**
     * @var \Magento\Eav\Api\AttributeRepositoryInterface
     */
    protected $attributeRepository;
    /**
     * @var \Magento\Catalog\Model\CategoryFactory
     */
    protected $categoryFactory;

    /**
     * @var \Magento\Bundle\Api\Data\OptionInterfaceFactory
     */
    protected $optionInterfaceFactory;

    /**
     * @var \Magento\Bundle\Api\Data\LinkInterfaceFactory
     */
    protected $linkInterfaceFactory;

    /**
     * @var \Magento\Eav\Model\Config
     */
    protected $eavConfig;

    /**
     * @var \Magento\Catalog\Setup\CategorySetupFactory
     */
    protected $categorySetupFactory;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory
     */
    protected $eavAttributeFactory;

    /**
     * @var \Magento\ConfigurableProduct\Helper\Product\Options\Factory
     */
    protected $optionsFactory;

    /**
     * @var \Magento\Eav\Api\Data\AttributeOptionInterface
     */
    protected $attributeOptionInterface;

    /**
     * @var \Magento\CatalogInventory\Model\Stock\ItemFactory
     */
    protected $stockItemFactory;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    protected $orderCollectionFactory;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $order;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resource;
    /**
     * @var \Magento\Framework\App\DeploymentConfig;
     */
    protected $deploymentConfig;

    /**
     * @var \SalesIgniter\Rental\Api\ReservationOrdersRepositoryInterface
     */
    protected $reservationOrdersRepository;

    /**
     * @var \SalesIgniter\Rental\Helper\Calendar
     */
    protected $helperCalendar;

    /**
     * @var \SalesIgniter\Rental\Helper\Data
     */
    protected $helperRental;

    /**
     * @var OrderItemRepositoryInterface
     */
    protected $orderItemRepository;
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var \SalesIgniter\Rental\Api\StockManagementInterface
     */
    protected $stock;
    /**
     * @var \Magento\Sales\Model\Order\CreditmemoFactory
     */
    protected $creditMemoFactory;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;
    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $transaction;

    /**
     * @var int
     */
    protected $idCategory;

    /**
     * @var \Magento\Eav\Setup\EavSetupFactory
     */
    protected $eavSetupFactory;

    /**
     * @var \Magento\Framework\Setup\ModuleDataSetupInterface
     */
    protected $dataSetup;
    /**
     * @var \Magento\Catalog\Model\ResourceModel\Eav\Attribute
     */
    protected $eavAttribute;

    /**
     * @param int $count
     *
     * @return Category[]
     */
    private function getCategories($count)
    {
        /** @var Category $category */
        $category = $this->categoryFactory->create();

        $result = $category->getCollection()->getItems();
        $result = array_slice($result, 2);

        return array_slice($result, 0, $count);
    }

    public function createCategories($idCategory = 35, $nameCategory = 'Category 1')
    {
        /** @var Category $category */
        $category = $this->categoryFactory->create();

        $resultCategories = $category->getCollection()->getItems();
        foreach ($resultCategories as $oCategory) {
            if (in_array($oCategory->getId(), [$idCategory])) {
                $oCategory->delete();
            }
        }

        $categoryArray = $this->getCategories(1);
        $categoryBefore = end($categoryArray);
        $path = '';
        if (is_object($categoryBefore)) {
            $path = $categoryBefore->getPath();
        }

        $category = $this->categoryFactory->create();
        $category->isObjectNew(true);
        $category->setId(
            $idCategory
        )->setCreatedAt(
            '2014-06-23 09:50:07'
        )->setName(
            $nameCategory
        )->setParentId(
            2
        )->setPath(
            $path
        )->setAvailableSortBy(
            'name'
        )->setDefaultSortBy(
            'name'
        )->setIsActive(
            true
        )->setPosition(
            1
        )->save();
    }

    /*
     * TODO just make a single function with parameters. Is just stupid how is now but was faster
     *
     *
     */
    private function simpleProduct($idProduct, $idCategory, $price)
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(
            \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE
        )->setId($idProduct)
            ->setAttributeSetId(4)
            //->setStoreId(1)
            ->setWebsiteIds([1])
            ->setName('Simple Product Igniter'.$idProduct)
            ->setSku('simple'.$idProduct)
            ->setPrice($price)
            ->setWeight(18)
            ->setStockData(['use_config_manage_stock' => 0])
            ->setCategoryIds([$idCategory])
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED);
        $this->productRepository->save($product);
        /** @var \Magento\CatalogInventory\Model\Stock\Item $stockItem */
        $stockItem = $this->stockItemFactory->create();
        $stockItem->load($idProduct, 'product_id');

        if (!$stockItem->getProductId()) {
            $stockItem->setProductId($idProduct);
        }
        $stockItem->setUseConfigManageStock(1);
        $stockItem->setQty(1000);
        $stockItem->setIsQtyDecimal(0);
        $stockItem->setIsInStock(1);
        $stockItem->save();
    }

    public function removeEavAttribute($attr)
    {
        $atName = $attr[0];
        $eavConfig = $this->eavConfig;
        $attribute = $eavConfig->getAttribute('catalog_product', $atName);

        $eavConfig->clear();

        if ($attribute->getId()) {
            $this->attributeRepository->delete($attribute);
        }
    }

    private function reservationProduct($idProduct, $idCategory, $price)
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(
            \SalesIgniter\Rental\Model\Product\Type\Sirent::TYPE_RENTAL
        )->setId($idProduct)
            ->setAttributeSetId(4)
            //->setSirentRentalType(1)
            //->setSirentUseTimes(1)
            //->setSirentQuantity(1)
            //->setSirentPricingType(2)

            //->setStoreId(1)
            ->setWebsiteIds([1])
            ->setName(
                'Reservation Product Igniter'.$idProduct
            )->setSku(
                'reservation'.$idProduct
            )->setWeight(
                18
            )/*->setSize(
                [['website_id' => 0, 'country' => 'US', 'state' => 0, 'price' => 0, 'delete' => '']]
            )*/
            ->setShortDescription(
                'Short description'
            )
            ->setTaxClassId(0)
            ->setDescription(
                'Description with <b>html tag</b>'
            )->setCategoryIds(
                [$idCategory]
            )->setVisibility(
                Visibility::VISIBILITY_BOTH
            )->setStatus(
                Status::STATUS_ENABLED
            )->setSirentPrice(
                $price
            );
        $this->productRepository->save($product);

        /** @var \Magento\CatalogInventory\Model\Stock\Item $stockItem */
        $stockItem = $this->stockItemFactory->create();
        $stockItem->load($idProduct, 'product_id');

        if (!$stockItem->getProductId()) {
            $stockItem->setProductId($idProduct);
        }
        $stockItem->setUseConfigManageStock(0);
        $stockItem->setQty(0);
        $stockItem->setIsQtyDecimal(0);
        $stockItem->setIsInStock(1);
        $stockItem->save();
    }

    private function configurableProductReservation($idProduct, $associatedProductIds, $idCategory)
    {
        $eavConfig = $this->eavConfig;
        $attribute = $eavConfig->getAttribute('catalog_product', 'test_configurable');

        $eavConfig->clear();

        /** @var $installer \Magento\Catalog\Setup\CategorySetup */
        $installer = $this->categorySetupFactory->create();

        if (!$attribute->getId()) {

            /** @var $attribute \Magento\Catalog\Model\ResourceModel\Eav\Attribute */
            $attribute = $this->eavAttributeFactory->create();

            $attribute->setData(
                [
                    'attribute_code' => 'test_configurable',
                    'entity_type_id' => $installer->getEntityTypeId('catalog_product'),
                    'is_global' => 1,
                    'is_user_defined' => 1,
                    'frontend_input' => 'select',
                    'is_unique' => 0,
                    'is_required' => 0,
                    'is_searchable' => 0,
                    'is_visible_in_advanced_search' => 0,
                    'is_comparable' => 0,
                    'is_filterable' => 0,
                    'is_filterable_in_search' => 0,
                    'is_used_for_promo_rules' => 0,
                    'is_html_allowed_on_front' => 1,
                    'is_visible_on_front' => 0,
                    'used_in_product_listing' => 0,
                    'used_for_sort_by' => 0,
                    'frontend_label' => ['Test Configurable'],
                    'backend_type' => 'int',
                    'option' => [
                        'value' => ['option_0' => ['Option 1'], 'option_1' => ['Option 2']],
                        'order' => ['option_0' => 1, 'option_1' => 2],
                    ],
                ]
            );

            $this->attributeRepository->save($attribute);
        }

        /* Assign attribute to attribute set */
        $installer->addAttributeToGroup('catalog_product', 'Default', 'General', $attribute->getId());
        $eavConfig->clear();

        /** @var $installer CategorySetup */
        $installer = $this->categorySetupFactory->create();

        /* Create simple products per each option value*/
        /** @var AttributeOptionInterface[] $options */
        $options = $attribute->getOptions();

        $attributeValues = [];
        $attributeSetId = $installer->getAttributeSetId('catalog_product', 'Default');
        array_shift($options); //remove the first option which is empty
        $assocProdId = 0;
        foreach ($options as $option) {
            $productAssoc = $this->productRepository->getById($associatedProductIds[$assocProdId]);
            $productAssoc->setTestConfigurable($option->getValue());
            $productAssoc->save();
            $attributeValues[] = [
                'label' => 'test',
                'attribute_id' => $attribute->getId(),
                'value_index' => $option->getValue(),
            ];
            ++$assocProdId;
        }

        /** @var $product Product */
        $product = $this->productFactory->create();

        $configurableAttributesData = [
            [
                'attribute_id' => $attribute->getId(),
                'code' => $attribute->getAttributeCode(),
                'label' => $attribute->getStoreLabel(),
                'position' => '0',
                'values' => $attributeValues,
            ],
        ];

        $configurableOptions = $this->optionsFactory->create($configurableAttributesData);

        $extensionConfigurableAttributes = $product->getExtensionAttributes();
        $extensionConfigurableAttributes->setConfigurableProductOptions($configurableOptions);
        $extensionConfigurableAttributes->setConfigurableProductLinks($associatedProductIds);

        $product->setExtensionAttributes($extensionConfigurableAttributes);

        $product->setTypeId(Configurable::TYPE_CODE)
            ->setId($idProduct)
            ->setAttributeSetId($attributeSetId)
            ->setWebsiteIds([1])
            ->setName('Configurable Product'.$idProduct)
            ->setSku('configurable'.$idProduct)
            //->setSirentRentalType(1)
            //->setSirentUseTimes(1)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setCategoryIds(
                [$idCategory]
            )
            ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);

        $this->productRepository->save($product);
    }

    private function bundleProductReservation($idProduct, $idCategory, $params)
    {
        $options = [];
        $selections = [];
        foreach ($params['options'] as $optionsValue => $idValues) {
            if (strpos($optionsValue, 'radio') !== false) {
                $id = count($options);
                $options[] = [
                    'title' => 'Option '.$id,
                    'default_title' => 'Option '.$id,
                    'type' => 'radio',
                    'required' => $idValues['is_required'],
                    'delete' => '',
                ];
            }
            if (strpos($optionsValue, 'checkbox') !== false) {
                $id = count($options);
                $options[] = [
                    'title' => 'Option '.$id,
                    'default_title' => 'Option '.$id,
                    'type' => 'checkbox',
                    'required' => $idValues['is_required'],
                    'delete' => '',
                ];
            }
            if (strpos($optionsValue, 'multi') !== false) {
                $id = count($options);
                $options[] = [
                    'title' => 'Option '.$id,
                    'default_title' => 'Option '.$id,
                    'type' => 'multi',
                    'required' => $idValues['is_required'],
                    'delete' => '',
                ];
            }
            if (strpos($optionsValue, 'select') !== false) {
                $id = count($options);
                $options[] = [
                    'title' => 'Option '.$id,
                    'default_title' => 'Option '.$id,
                    'type' => 'select',
                    'required' => $idValues['is_required'],
                    'delete' => '',
                ];
            }
            $internalSelections = [];
            foreach ($idValues['id'] as $ids => $qtyOptions) {
                $internalSelections[] =
                    [
                        'product_id' => $ids,
                        'selection_qty' => $qtyOptions['qty'],
                        'selection_can_change_qty' => $qtyOptions['can_change'],
                        'delete' => '',
                        'option_id' => $id + 1,
                    ];
            }
            $selections[] = $internalSelections;
        }

        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE)
            ->setId($idProduct)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Bundle Product'.$idProduct)
            ->setSku('bundle-product'.$idProduct)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1])
            ->setPriceView(1)
            ->setPriceType(1)
            //->setSirentRentalType($params['price_type'])
            //->setSirentUseTimes(0)
            ->setPrice(10.0)
            ->setSirentPrice([])
            ->setShipmentType(0)
            ->setCategoryIds(
                [$idCategory]
            )
            ->setBundleOptionsData(

                $options

            )->setBundleSelectionsData(

                $selections

            );
        $this->productRepository->save($product);
        if ($product->getBundleOptionsData()) {
            $options = [];
            foreach ($product->getBundleOptionsData() as $key => $optionData) {
                if (!(bool) $optionData['delete']) {
                    $option = $this->optionInterfaceFactory
                        ->create(['data' => $optionData]);
                    $option->setSku($product->getSku());
                    $option->setOptionId(null);

                    $links = [];
                    $bundleLinks = $product->getBundleSelectionsData();
                    if (!empty($bundleLinks[$key])) {
                        foreach ($bundleLinks[$key] as $linkData) {
                            if (!(bool) $linkData['delete']) {
                                $link = $this->linkInterfaceFactory
                                    ->create(['data' => $linkData]);
                                $linkProduct = $this->productRepository->getById($linkData['product_id']);
                                $link->setSku($linkProduct->getSku());
                                $link->setQty($linkData['selection_qty']);
                                if (isset($linkData['selection_can_change_qty'])) {
                                    $link->setCanChangeQuantity($linkData['selection_can_change_qty']);
                                }
                                $links[] = $link;
                            }
                        }
                        $option->setProductLinks($links);
                        $options[] = $option;
                    }
                }
            }
            $extension = $product->getExtensionAttributes();
            $extension->setBundleProductOptions($options);
            $product->setExtensionAttributes($extension);
        }
        $this->productRepository->save($product);
    }

    /**
     * @param array $attributes
     */
    protected function removeProductEavAttributes($attributes)
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->dataSetup]);

        foreach ($attributes as $attributeName) {
            $eavSetup->removeAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                $attributeName);
        }
    }

    /**
     * @param array $attributes
     */
    protected function removeCustomerEavAttributes($attributes)
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->dataSetup]);
        foreach ($attributes as $attributeName) {
            $eavSetup->removeAttribute(
                Customer::ENTITY,
                $attributeName);
        }
    }

    protected function createProducts($params)
    {
        if ($params['type'] === 'reservation') {
            $this->reservationProduct($params['id'], $params['idCategory'], $params['price']);
        }
        if ($params['type'] === 'simple') {
            $this->simpleProduct($params['id'], $params['idCategory'], $params['price']);
        }
        if ($params['type'] === 'bundle') {
            $this->bundleProductReservation($params['id'], $params['idCategory'], $params);
        }
    }

    protected function deleteAllProducts()
    {
        /* @var Category $category */
        $product = $this->productFactory->create();
        $resultProducts = $product->getCollection()->getItems();
        /** @var \Magento\Catalog\Model\Product $oProduct */
        foreach ($resultProducts as $oProduct) {
            $this->productRepository->delete($oProduct);
        }
    }

    /***
     * All functions down here cam be removed
     * kept only for reference
     *
     *
     */

    protected function createAllProductsOld($params)
    {
        $this->idCategory = 35;
        $idProducts = [100, 101, 102, 103, 104];
        $idProductsReservation = [120, 121, 122, 123, 124, 125, 126, 127, 128, 129];
        $idProductsReservationBundleOnlyMultipleOptions = [130 => [127, 128]];
        $idProductsReservationBundleOnly = [131 => [129]];
        $idProductsReservationBundleAndSimple = [132 => [127, 100]];
        $idProductsConfigurable = [80 => [120, 121]];

        $this->deleteProductsAndCategories();
        $this->createCategories();
        foreach ($idProducts as $iProduct) {
            $this->simpleProduct($iProduct);
        }

        foreach ($idProductsReservation as $iProduct) {
            $this->reservationProduct($iProduct);
        }

        foreach ($idProductsConfigurable as $iProduct => $idConfigLinked) {
            $this->configurableProductReservation($iProduct, $idConfigLinked);
        }

        foreach ($idProductsReservationBundleOnly as $iProduct => $idBundleLinked) {
            $this->bundleProductReservationOnly($iProduct, $idBundleLinked);
        }
        foreach ($idProductsReservationBundleOnlyMultipleOptions as $iProduct => $idBundleLinked) {
            $this->bundleProductReservationOnlyMultipleOptions($iProduct, $idBundleLinked);
        }

        foreach ($idProductsReservationBundleAndSimple as $iProduct => $idBundleLinked) {
            $this->bundleProductReservationAndSimple($iProduct, $idBundleLinked);
        }
    }

    private function bundleProductReservationOnlyRadioRequired($idProduct, $idProductsReservationBundle)
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE)
            ->setId($idProduct)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Bundle Product'.$idProduct)
            ->setSku('bundle-product'.$idProduct)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1])
            ->setPriceView(1)
            ->setPriceType(1)
            ->setSirentRentalType(1)
            ->setSirentUseTimes(1)
            ->setPrice(10.0)
            ->setShipmentType(0)
            ->setCategoryIds(
                [$this->idCategory]
            )
            ->setBundleOptionsData(
                [

                    // Required "Radio Buttons" option
                    [
                        'title' => 'Option 2',
                        'default_title' => 'Option 2',
                        'type' => 'radio',
                        'required' => 1,
                        'delete' => '',
                    ],
                ]
            )->setBundleSelectionsData(
                [
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                    ],
                ]
            );

        if ($product->getBundleOptionsData()) {
            $optionsBundle = [];
            foreach ($product->getBundleOptionsData() as $key => $optionData) {
                if (!(bool) $optionData['delete']) {
                    $option = $this->optionInterfaceFactory
                        ->create(['data' => $optionData]);
                    $option->setSku($product->getSku());
                    $option->setOptionId(null);

                    $links = [];
                    $bundleLinks = $product->getBundleSelectionsData();
                    if (!empty($bundleLinks[$key])) {
                        foreach ($bundleLinks[$key] as $linkData) {
                            if (!(bool) $linkData['delete']) {
                                $link = $this->linkInterfaceFactory
                                    ->create(['data' => $linkData]);
                                $linkProduct = $this->productRepository->getById($linkData['product_id']);
                                $link->setSku($linkProduct->getSku());
                                $link->setQty($linkData['selection_qty']);
                                if (isset($linkData['selection_can_change_qty'])) {
                                    $link->setCanChangeQuantity($linkData['selection_can_change_qty']);
                                }
                                $links[] = $link;
                            }
                        }
                        $option->setProductLinks($links);
                        $optionsBundle[] = $option;
                    }
                }
            }
            $extension = $product->getExtensionAttributes();
            $extension->setBundleProductOptions($optionsBundle);
            $product->setExtensionAttributes($extension);
        }
        $this->productRepository->save($product);
        //$product->save();
//        $this->helperInit->initializeFromData($product, $product->getData());
    }

    private function bundleProductReservationOnlyCheckboxRequired($idProduct, $idProductsReservationBundle)
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE)
            ->setId($idProduct)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Bundle Product'.$idProduct)
            ->setSku('bundle-product'.$idProduct)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1])
            ->setPriceView(1)
            ->setPriceType(1)
            ->setSirentRentalType(1)
            ->setSirentUseTimes(1)
            ->setPrice(10.0)
            ->setShipmentType(0)
            ->setCategoryIds(
                [$this->idCategory]
            )
            ->setBundleOptionsData(
                [

                    // Required "Checkbox" option
                    [
                        'title' => 'Option 3',
                        'default_title' => 'Option 3',
                        'type' => 'checkbox',
                        'required' => 1,
                        'delete' => '',
                    ],
                ]
            )->setBundleSelectionsData(
                [
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                    ],
                ]
            );

        if ($product->getBundleOptionsData()) {
            $options = [];
            foreach ($product->getBundleOptionsData() as $key => $optionData) {
                if (!(bool) $optionData['delete']) {
                    $option = $this->optionInterfaceFactory
                        ->create(['data' => $optionData]);
                    $option->setSku($product->getSku());
                    $option->setOptionId(null);

                    $links = [];
                    $bundleLinks = $product->getBundleSelectionsData();
                    if (!empty($bundleLinks[$key])) {
                        foreach ($bundleLinks[$key] as $linkData) {
                            if (!(bool) $linkData['delete']) {
                                $link = $this->linkInterfaceFactory
                                    ->create(['data' => $linkData]);
                                $linkProduct = $this->productRepository->getById($linkData['product_id']);
                                $link->setSku($linkProduct->getSku());
                                $link->setQty($linkData['selection_qty']);
                                if (isset($linkData['selection_can_change_qty'])) {
                                    $link->setCanChangeQuantity($linkData['selection_can_change_qty']);
                                }
                                $links[] = $link;
                            }
                        }
                        $option->setProductLinks($links);
                        $options[] = $option;
                    }
                }
            }
            $extension = $product->getExtensionAttributes();
            $extension->setBundleProductOptions($options);
            $product->setExtensionAttributes($extension);
        }
        $this->productRepository->save($product);
        //$product->save();
//        $this->helperInit->initializeFromData($product, $product->getData());
    }

    private function bundleProductReservationOnlyMultiselectRequired($idProduct, $idProductsReservationBundle)
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE)
            ->setId($idProduct)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Bundle Product'.$idProduct)
            ->setSku('bundle-product'.$idProduct)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1])
            ->setPriceView(1)
            ->setPriceType(1)
            ->setSirentRentalType(1)
            ->setSirentUseTimes(1)
            ->setPrice(10.0)
            ->setShipmentType(0)
            ->setCategoryIds(
                [$this->idCategory]
            )
            ->setBundleOptionsData(
                [

                    // Required "Multiple Select" option
                    [
                        'title' => 'Option 4',
                        'default_title' => 'Option 4',
                        'type' => 'multi',
                        'required' => 1,
                        'delete' => '',
                    ],
                ]
            )->setBundleSelectionsData(
                [
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                    ],
                ]
            );

        if ($product->getBundleOptionsData()) {
            $options = [];
            foreach ($product->getBundleOptionsData() as $key => $optionData) {
                if (!(bool) $optionData['delete']) {
                    $option = $this->optionInterfaceFactory
                        ->create(['data' => $optionData]);
                    $option->setSku($product->getSku());
                    $option->setOptionId(null);

                    $links = [];
                    $bundleLinks = $product->getBundleSelectionsData();
                    if (!empty($bundleLinks[$key])) {
                        foreach ($bundleLinks[$key] as $linkData) {
                            if (!(bool) $linkData['delete']) {
                                $link = $this->linkInterfaceFactory
                                    ->create(['data' => $linkData]);
                                $linkProduct = $this->productRepository->getById($linkData['product_id']);
                                $link->setSku($linkProduct->getSku());
                                $link->setQty($linkData['selection_qty']);
                                if (isset($linkData['selection_can_change_qty'])) {
                                    $link->setCanChangeQuantity($linkData['selection_can_change_qty']);
                                }
                                $links[] = $link;
                            }
                        }
                        $option->setProductLinks($links);
                        $options[] = $option;
                    }
                }
            }
            $extension = $product->getExtensionAttributes();
            $extension->setBundleProductOptions($options);
            $product->setExtensionAttributes($extension);
        }
        $this->productRepository->save($product);
        //$product->save();
//        $this->helperInit->initializeFromData($product, $product->getData());
    }

    private function bundleProductReservationOnlyCheckboxMultiselectRequired($idProduct, $idProductsReservationBundle)
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE)
            ->setId($idProduct)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Bundle Product'.$idProduct)
            ->setSku('bundle-product'.$idProduct)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1])
            ->setPriceView(1)
            ->setPriceType(1)
            ->setSirentRentalType(1)
            ->setSirentUseTimes(1)
            ->setPrice(10.0)
            ->setShipmentType(0)
            ->setCategoryIds(
                [$this->idCategory]
            )
            ->setBundleOptionsData(
                [
                    // Required "Checkbox" option
                    [
                        'title' => 'Option 3',
                        'default_title' => 'Option 3',
                        'type' => 'checkbox',
                        'required' => 1,
                        'delete' => '',
                    ],
                    // Required "Multiple Select" option
                    [
                        'title' => 'Option 4',
                        'default_title' => 'Option 4',
                        'type' => 'multi',
                        'required' => 1,
                        'delete' => '',
                    ],
                ]
            )->setBundleSelectionsData(
                [
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 2,
                            'selection_can_change_qty' => 0,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                    ],
                    [
                        [
                            'product_id' => $idProductsReservationBundle[2],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 2,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[3],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 2,
                        ],
                    ],
                ]
            );

        if ($product->getBundleOptionsData()) {
            $options = [];
            foreach ($product->getBundleOptionsData() as $key => $optionData) {
                if (!(bool) $optionData['delete']) {
                    $option = $this->optionInterfaceFactory
                        ->create(['data' => $optionData]);
                    $option->setSku($product->getSku());
                    $option->setOptionId(null);

                    $links = [];
                    $bundleLinks = $product->getBundleSelectionsData();
                    if (!empty($bundleLinks[$key])) {
                        foreach ($bundleLinks[$key] as $linkData) {
                            if (!(bool) $linkData['delete']) {
                                $link = $this->linkInterfaceFactory
                                    ->create(['data' => $linkData]);
                                $linkProduct = $this->productRepository->getById($linkData['product_id']);
                                $link->setSku($linkProduct->getSku());
                                $link->setQty($linkData['selection_qty']);
                                if (isset($linkData['selection_can_change_qty'])) {
                                    $link->setCanChangeQuantity($linkData['selection_can_change_qty']);
                                }
                                $links[] = $link;
                            }
                        }
                        $option->setProductLinks($links);
                        $options[] = $option;
                    }
                }
            }
            $extension = $product->getExtensionAttributes();
            $extension->setBundleProductOptions($options);
            $product->setExtensionAttributes($extension);
        }
        $this->productRepository->save($product);
        //$product->save();
//        $this->helperInit->initializeFromData($product, $product->getData());
    }

    private function bundleProductReservationOnlyRadioMultiselectRequired($idProduct, $idProductsReservationBundle)
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE)
            ->setId($idProduct)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Bundle Product'.$idProduct)
            ->setSku('bundle-product'.$idProduct)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1])
            ->setPriceView(1)
            ->setPriceType(1)
            ->setSirentRentalType(1)
            ->setSirentUseTimes(1)
            ->setPrice(10.0)
            ->setShipmentType(0)
            ->setCategoryIds(
                [$this->idCategory]
            )
            ->setBundleOptionsData(
                [
                    // Required "Checkbox" option
                    [
                        'title' => 'Option 3',
                        'default_title' => 'Option 3',
                        'type' => 'radio',
                        'required' => 1,
                        'delete' => '',
                    ],
                    // Required "Multiple Select" option
                    [
                        'title' => 'Option 4',
                        'default_title' => 'Option 4',
                        'type' => 'multi',
                        'required' => 1,
                        'delete' => '',
                    ],
                ]
            )->setBundleSelectionsData(
                [
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 2,
                            'selection_can_change_qty' => 0,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                    ],
                    [
                        [
                            'product_id' => $idProductsReservationBundle[2],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 2,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[3],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 2,
                        ],
                    ],
                ]
            );

        if ($product->getBundleOptionsData()) {
            $options = [];
            foreach ($product->getBundleOptionsData() as $key => $optionData) {
                if (!(bool) $optionData['delete']) {
                    $option = $this->optionInterfaceFactory
                        ->create(['data' => $optionData]);
                    $option->setSku($product->getSku());
                    $option->setOptionId(null);

                    $links = [];
                    $bundleLinks = $product->getBundleSelectionsData();
                    if (!empty($bundleLinks[$key])) {
                        foreach ($bundleLinks[$key] as $linkData) {
                            if (!(bool) $linkData['delete']) {
                                $link = $this->linkInterfaceFactory
                                    ->create(['data' => $linkData]);
                                $linkProduct = $this->productRepository->getById($linkData['product_id']);
                                $link->setSku($linkProduct->getSku());
                                $link->setQty($linkData['selection_qty']);
                                if (isset($linkData['selection_can_change_qty'])) {
                                    $link->setCanChangeQuantity($linkData['selection_can_change_qty']);
                                }
                                $links[] = $link;
                            }
                        }
                        $option->setProductLinks($links);
                        $options[] = $option;
                    }
                }
            }
            $extension = $product->getExtensionAttributes();
            $extension->setBundleProductOptions($options);
            $product->setExtensionAttributes($extension);
        }
        $this->productRepository->save($product);
        //$product->save();
//        $this->helperInit->initializeFromData($product, $product->getData());
    }

    private function bundleProductReservationOnlyMultipleOptions($idProduct, $idProductsReservationBundle)
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE)
            ->setId($idProduct)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Bundle Product'.$idProduct)
            ->setSku('bundle-product'.$idProduct)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1])
            ->setPriceView(1)
            ->setPriceType(1)
            ->setSirentRentalType(1)
            ->setSirentUseTimes(1)
            ->setPrice(10.0)
            ->setShipmentType(0)
            ->setCategoryIds(
                [$this->idCategory]
            )
            ->setBundleOptionsData(
                [
                    // Required "Drop-down" option
                    [
                        'title' => 'Option 1',
                        'default_title' => 'Option 1',
                        'type' => 'select',
                        'required' => 1,
                        'delete' => '',
                    ],
                    // Required "Radio Buttons" option
                    [
                        'title' => 'Option 2',
                        'default_title' => 'Option 2',
                        'type' => 'radio',
                        'required' => 1,
                        'delete' => '',
                    ],
                    // Required "Checkbox" option
                    [
                        'title' => 'Option 3',
                        'default_title' => 'Option 3',
                        'type' => 'checkbox',
                        'required' => 1,
                        'delete' => '',
                    ],
                    // Required "Multiple Select" option
                    [
                        'title' => 'Option 4',
                        'default_title' => 'Option 4',
                        'type' => 'multi',
                        'required' => 1,
                        'delete' => '',
                    ],
                    // Non-required "Multiple Select" option
                    [
                        'title' => 'Option 5',
                        'default_title' => 'Option 5',
                        'type' => 'multi',
                        'required' => 0,
                        'delete' => '',
                    ],
                ]
            )->setBundleSelectionsData(
                [
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                    ],
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 2,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 2,
                        ],
                    ],
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 3,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 3,
                        ],
                    ],
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 4,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 4,
                        ],
                    ],
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 5,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 5,
                        ],
                    ],
                ]
            );

        if ($product->getBundleOptionsData()) {
            $options = [];
            foreach ($product->getBundleOptionsData() as $key => $optionData) {
                if (!(bool) $optionData['delete']) {
                    $option = $this->optionInterfaceFactory
                        ->create(['data' => $optionData]);
                    $option->setSku($product->getSku());
                    $option->setOptionId(null);

                    $links = [];
                    $bundleLinks = $product->getBundleSelectionsData();
                    if (!empty($bundleLinks[$key])) {
                        foreach ($bundleLinks[$key] as $linkData) {
                            if (!(bool) $linkData['delete']) {
                                $link = $this->linkInterfaceFactory
                                    ->create(['data' => $linkData]);
                                $linkProduct = $this->productRepository->getById($linkData['product_id']);
                                $link->setSku($linkProduct->getSku());
                                $link->setQty($linkData['selection_qty']);
                                if (isset($linkData['selection_can_change_qty'])) {
                                    $link->setCanChangeQuantity($linkData['selection_can_change_qty']);
                                }
                                $links[] = $link;
                            }
                        }
                        $option->setProductLinks($links);
                        $options[] = $option;
                    }
                }
            }
            $extension = $product->getExtensionAttributes();
            $extension->setBundleProductOptions($options);
            $product->setExtensionAttributes($extension);
        }
        $this->productRepository->save($product);
        //$product->save();
//        $this->helperInit->initializeFromData($product, $product->getData());
    }

    private function bundleProductReservationAndSimple($idProduct, $idProductsReservationBundle)
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE)
            ->setId($idProduct)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Bundle Product'.$idProduct)
            ->setSku('bundle-product'.$idProduct)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1])
            ->setPriceView(1)
            ->setPriceType(1)
            ->setSirentRentalType(1)
            ->setSirentUseTimes(1)
            ->setPrice(10.0)
            ->setShipmentType(0)
            ->setCategoryIds(
                [$this->idCategory]
            )
            ->setBundleOptionsData(
                [
                    // Required "Drop-down" option
                    [
                        'title' => 'Option 1',
                        'default_title' => 'Option 1',
                        'type' => 'select',
                        'required' => 1,
                        'delete' => '',
                    ],
                    // Required "Radio Buttons" option
                    [
                        'title' => 'Option 2',
                        'default_title' => 'Option 2',
                        'type' => 'radio',
                        'required' => 1,
                        'delete' => '',
                    ],
                    // Required "Checkbox" option
                    [
                        'title' => 'Option 3',
                        'default_title' => 'Option 3',
                        'type' => 'checkbox',
                        'required' => 1,
                        'delete' => '',
                    ],
                    // Required "Multiple Select" option
                    [
                        'title' => 'Option 4',
                        'default_title' => 'Option 4',
                        'type' => 'multi',
                        'required' => 1,
                        'delete' => '',
                    ],
                    // Non-required "Multiple Select" option
                    [
                        'title' => 'Option 5',
                        'default_title' => 'Option 5',
                        'type' => 'multi',
                        'required' => 0,
                        'delete' => '',
                    ],
                ]
            )->setBundleSelectionsData(
                [
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 1,
                        ],
                    ],
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 2,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                            'option_id' => 2,
                        ],
                    ],
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 3,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 3,
                        ],
                    ],
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 4,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 4,
                        ],
                    ],
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 5,
                        ],
                        [
                            'product_id' => $idProductsReservationBundle[1],
                            'selection_qty' => 1,
                            'delete' => '',
                            'option_id' => 5,
                        ],
                    ],
                ]
            );

        if ($product->getBundleOptionsData()) {
            $options = [];
            foreach ($product->getBundleOptionsData() as $key => $optionData) {
                if (!(bool) $optionData['delete']) {
                    $option = $this->optionInterfaceFactory
                        ->create(['data' => $optionData]);
                    $option->setSku($product->getSku());
                    $option->setOptionId(null);

                    $links = [];
                    $bundleLinks = $product->getBundleSelectionsData();
                    if (!empty($bundleLinks[$key])) {
                        foreach ($bundleLinks[$key] as $linkData) {
                            if (!(bool) $linkData['delete']) {
                                $link = $this->linkInterfaceFactory
                                    ->create(['data' => $linkData]);
                                $linkProduct = $this->productRepository->getById($linkData['product_id']);
                                $link->setSku($linkProduct->getSku());
                                $link->setQty($linkData['selection_qty']);
                                if (isset($linkData['selection_can_change_qty'])) {
                                    $link->setCanChangeQuantity($linkData['selection_can_change_qty']);
                                }
                                $links[] = $link;
                            }
                        }
                        $option->setProductLinks($links);
                        $options[] = $option;
                    }
                }
            }
            $extension = $product->getExtensionAttributes();
            $extension->setBundleProductOptions($options);
            $product->setExtensionAttributes($extension);
        }
        $this->productRepository->save($product);
        //$product->save();
        //$this->helperInit->initializeFromData($product, $product->getData());
    }

    private function bundleProductReservationOnly($idProduct, $idProductsReservationBundle)
    {
        /** @var $product \Magento\Catalog\Model\Product */
        $product = $this->productFactory->create();
        $product->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_BUNDLE)
            ->setId($idProduct)
            ->setAttributeSetId(4)
            ->setWebsiteIds([1])
            ->setName('Bundle Product'.$idProduct)
            ->setSku('bundle-product'.$idProduct)
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_qty_decimal' => 0, 'is_in_stock' => 1])
            ->setPriceView(1)
            ->setPriceType(1)
            ->setSirentRentalType(1)
            ->setSirentUseTimes(1)
            ->setPrice(11.0)
            ->setShipmentType(0)
            ->setCategoryIds(
                [$this->idCategory]
            )
            ->setBundleOptionsData(
                [
                    [
                        'title' => 'Bundle Product Items',
                        'default_title' => 'Bundle Product Items',
                        'type' => 'select', 'required' => 1,
                        'delete' => '',
                    ],
                ]
            )
            ->setBundleSelectionsData(
                [
                    [
                        [
                            'product_id' => $idProductsReservationBundle[0],
                            'selection_qty' => 1,
                            'selection_can_change_qty' => 1,
                            'delete' => '',
                        ],
                    ],
                ]
            );

        if ($product->getBundleOptionsData()) {
            $options = [];
            foreach ($product->getBundleOptionsData() as $key => $optionData) {
                if (!(bool) $optionData['delete']) {
                    $option = $this->optionInterfaceFactory
                        ->create(['data' => $optionData]);
                    $option->setSku($product->getSku());
                    $option->setOptionId(null);

                    $links = [];
                    $bundleLinks = $product->getBundleSelectionsData();
                    if (!empty($bundleLinks[$key])) {
                        foreach ($bundleLinks[$key] as $linkData) {
                            if (!(bool) $linkData['delete']) {
                                $link = $this->linkInterfaceFactory
                                    ->create(['data' => $linkData]);
                                $linkProduct = $this->productRepository->getById($linkData['product_id']);
                                $link->setSku($linkProduct->getSku());
                                $link->setQty($linkData['selection_qty']);
                                if (isset($linkData['selection_can_change_qty'])) {
                                    $link->setCanChangeQuantity($linkData['selection_can_change_qty']);
                                }
                                $links[] = $link;
                            }
                        }
                        $option->setProductLinks($links);
                        $options[] = $option;
                    }
                }
            }
            $extension = $product->getExtensionAttributes();
            $extension->setBundleProductOptions($options);
            $product->setExtensionAttributes($extension);
        }
        $this->productRepository->save($product);
        //$product->save();
        //$this->helperInit->initializeFromData($product, $product->getData());
    }

    private function deleteProductsAndCategories()
    {
        /** @var Category $category */
        $category = $this->categoryFactory->create();

        $resultCategories = $category->getCollection()->getItems();
        foreach ($resultCategories as $oCategory) {
            if (in_array($oCategory->getId(), [$this->idCategory])) {
                $oCategory->delete();
            }
        }

        /* @var Category $category */
        $product = $this->productFactory->create();

        $resultProducts = $product->getCollection()->getItems();

        /** @var \Magento\Catalog\Model\Product $oProduct */
        foreach ($resultProducts as $oProduct) {
            //if (in_array($oProduct->getId(), $this->idProducts) ||
            //  in_array($oProduct->getId(), $this->idProductsReservation)
            //) {
            //$oProduct->delete();
            $this->productRepository->delete($oProduct);
            //}
        }
    }
}
