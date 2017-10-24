<?php
/**
 * Copyright © 2017 SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */

namespace SalesIgniter\Common\Console\Command;

use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ObjectManagerFactory;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\ObjectManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class UpdateAttributesCommand.
 */
class RunTestCommand extends CreateProductsCommand
{
    const TEST_ID = 'test_id';
    const ATTRIBUTE_VALUE = 'attribute_value';
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var ObjectManagerFactory
     */
    protected $objectManagerFactory;

    /**
     * Constructor.
     *
     * @param ObjectManagerFactory $objectManagerFactory
     */
    public function __construct(ObjectManagerFactory $objectManagerFactory)
    {
        $this->objectManagerFactory = $objectManagerFactory;
        parent::__construct();
    }

    protected function initMembers()
    {
        $this->registry = $this->getObjectManager()->create('\Magento\Framework\Registry');
        $this->shippingRate = $this->getObjectManager()->create('\Magento\Quote\Model\Quote\Address\Rate');
        $this->cartManagementInterface = $this->getObjectManager()->create('\Magento\Quote\Api\CartManagementInterface');
        $this->cartRepositoryInterface = $this->getObjectManager()->create('\Magento\Quote\Api\CartRepositoryInterface');
        $this->orderService = $this->getObjectManager()->create('\Magento\Sales\Model\Service\OrderService');
        $this->customerRepository = $this->getObjectManager()->create('\Magento\Customer\Api\CustomerRepositoryInterface');
        $this->customerFactory = $this->getObjectManager()->create('\Magento\Customer\Model\CustomerFactory');
        $this->quoteManagement = $this->getObjectManager()->create('\Magento\Quote\Model\QuoteManagement');
        $this->storeManager = $this->getObjectManager()->create('\Magento\Store\Model\StoreManagerInterface');
        $this->productFactory = $this->getObjectManager()->create('\Magento\Catalog\Model\ProductFactory');
        $this->attributeAction = $this->getObjectManager()->create('\Magento\Catalog\Model\Product\Action');
        $this->productRepository = $this->getObjectManager()->create('\Magento\Catalog\Api\ProductRepositoryInterface');

        $this->reservationOrdersRepository = $this->getObjectManager()->create('\SalesIgniter\Rental\Api\ReservationOrdersRepositoryInterface');
        $this->orderCollectionFactory = $this->getObjectManager()->create('\Magento\Sales\Model\ResourceModel\Order\CollectionFactory');
        $this->stockItemFactory = $this->getObjectManager()->create('\Magento\CatalogInventory\Model\Stock\ItemFactory');
        $this->attributeOptionInterface = $this->getObjectManager()->create('\Magento\Eav\Api\Data\AttributeOptionInterface');
        $this->optionsFactory = $this->getObjectManager()->create('\Magento\ConfigurableProduct\Helper\Product\Options\Factory');
        $this->eavAttributeFactory = $this->getObjectManager()->create('\Magento\Catalog\Model\ResourceModel\Eav\AttributeFactory');
        $this->categorySetupFactory = $this->getObjectManager()->create('\Magento\Catalog\Setup\CategorySetupFactory');
        $this->eavConfig = $this->getObjectManager()->create('\Magento\Eav\Model\Config');
        $this->linkInterfaceFactory = $this->getObjectManager()->create('\Magento\Bundle\Api\Data\LinkInterfaceFactory');
        $this->optionInterfaceFactory = $this->getObjectManager()->create('\Magento\Bundle\Api\Data\OptionInterfaceFactory');
        $this->categoryFactory = $this->getObjectManager()->create('\Magento\Catalog\Model\CategoryFactory');
        $this->attributeRepository = $this->getObjectManager()->create('\Magento\Eav\Api\AttributeRepositoryInterface');

        $this->orderItemRepository = $this->getObjectManager()->create('\Magento\Sales\Api\OrderItemRepositoryInterface');
        $this->orderRepository = $this->getObjectManager()->create('\Magento\Sales\Api\OrderRepositoryInterface');

        $this->searchCriteriaBuilder = $this->getObjectManager()->create('\Magento\Framework\Api\SearchCriteriaBuilder');
        $this->stockBase = $this->getObjectManager()->create('\SalesIgniter\Rental\Model\Product\Stock');
        $this->stock = $this->getObjectManager()->create('\SalesIgniter\Rental\Api\StockManagementInterface');
        $this->creditMemoFactory = $this->getObjectManager()->create('\Magento\Sales\Model\Order\CreditmemoFactory');
        $this->order = $this->getObjectManager()->create('\Magento\Sales\Model\Order');
        $this->invoiceService = $this->getObjectManager()->create('\Magento\Sales\Model\Service\InvoiceService');
        $this->transaction = $this->getObjectManager()->create('\Magento\Framework\DB\Transaction');
        $this->_resource = $this->getObjectManager()->create('\Magento\Framework\App\ResourceConnection');
        $this->deploymentConfig = $this->getObjectManager()->create('\Magento\Framework\App\DeploymentConfig');
        $this->helperCalendar = $this->getObjectManager()->create('\SalesIgniter\Rental\Helper\Calendar');
        $this->eavAttribute = $this->getObjectManager()->create('\Magento\Catalog\Model\ResourceModel\Eav\Attribute');
        $this->eavSetupFactory = $this->getObjectManager()->create('\Magento\Eav\Setup\EavSetupFactory');
        $this->dataSetup = $this->getObjectManager()->create('\Magento\Framework\Setup\ModuleDataSetupInterface');
        $this->helperRental = $this->getObjectManager()->create('\SalesIgniter\Rental\Helper\Data');
    }

    /**
     * @param $orderId
     */
    protected function deleteOrder($orderId)
    {
        $this->stock->deleteReservationsByOrderId($orderId);
    }

    /**
     * @param $fixtures
     *
     * @return bool
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\StateException
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function updateProductsByFixtures($fixtures)
    {
        if (is_array($fixtures[0]) && array_key_exists('products', $fixtures[0])) {
            foreach ($fixtures[0]['products'] as $productConfig) {
                if (array_key_exists('product', $productConfig)) {
                    if (array_key_exists(0, $productConfig['product'])) {
                        foreach ($productConfig['product'] as $product) {
                            $this->updateProduct($product);
                        }
                    } else {
                        $this->updateProduct($productConfig['product']);
                    }
                } else {
                    if (array_key_exists(0, $productConfig)) {
                        foreach ($productConfig as $product) {
                            $this->updateProduct($product);
                        }
                    } else {
                        $this->updateProduct($productConfig);
                    }
                }
            }
        }

        return true;
    }

    /**
     * Gets initialized object manager.
     *
     * @return ObjectManagerInterface
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getObjectManager()
    {
        if (null === $this->objectManager) {
            $area = FrontNameResolver::AREA_CODE;
            $this->objectManager = $this->objectManagerFactory->create($_SERVER);
            /** @var \Magento\Framework\App\State $appState */
            $appState = $this->objectManager->get('Magento\Framework\App\State');
            $appState->setAreaCode($area);
            $configLoader = $this->objectManager->get('Magento\Framework\ObjectManager\ConfigLoaderInterface');
            $this->objectManager->configure($configLoader->load($area));
        }

        return $this->objectManager;
    }

    protected function showInventory($productId)
    {
        $inventory = $this->stock->getInventoryTable($productId);
        print_r($inventory);
    }

    protected function resetInventoryAll()
    {
        $products = $this->productRepository->getList($this->searchCriteriaBuilder->create());

        foreach ($products->getItems() as $product) {
            $productId = $product->getId();
            $stockClass = $this->getObjectManager()->create('\SalesIgniter\Rental\Model\Product\Stock');
            call_user_func_array([$stockClass, 'resetInventory'], [$productId]);
        }
    }

    protected function removeReservationsByDeletedOrders()
    {
        //$this->searchCriteriaBuilder->addFilter('entity_id', 0, 'gt');
        $criteria = $this->searchCriteriaBuilder->create();
        $orders = $this->orderRepository->getList($criteria);
        $ordersIds = [];
        foreach ($orders->getItems() as $order) {
            $ordersIds[] = $order->getId();
        }
        $this->searchCriteriaBuilder->addFilter('main_table.order_id', $ordersIds, 'nin');
        $this->searchCriteriaBuilder->addFilter('main_table.order_id', 0, 'gt');
        $criteria = $this->searchCriteriaBuilder->create();
        $resorders = $this->reservationOrdersRepository->getList($criteria);
        foreach ($resorders->getItems() as $resOrder) {
            $resOrder->delete();
        }
    }

    /**
     * @param null|array $productIds
     */
    protected function regenerateInventory($productIds = null)
    {
        $this->removeReservationsByDeletedOrders();
        if ($productIds === null) {
            $productIds = [];
            //if (count($productIds)) {
            //  $this->searchCriteriaBuilder
            //    ->addFilter('entity_id', $productIds, 'in');
            //}

            $criteria = $this->searchCriteriaBuilder->create();
            $products = $this->productRepository->getList($criteria);
            foreach ($products->getItems() as $product) {
                $productIds[] = $product->getId();
            }
        }
        if (!is_array($productIds)) {
            $productIds = [$productIds];
        }
        foreach ($productIds as $productId) {
            $stockClass = $this->getObjectManager()->create('\SalesIgniter\Rental\Model\Product\Stock');
            call_user_func_array([$stockClass, 'resetInventory'], [$productId]);
        }

        //$this->searchCriteriaBuilder->addFilter('product_id', $productId);
        $this->searchCriteriaBuilder->addFilter('qty_use_grid', 0, 'gt');
        $criteria = $this->searchCriteriaBuilder->create();
        $items = $this->reservationOrdersRepository->getList($criteria)->getItems();
        foreach ($items as $item) {
            $stockManagementClass = $this->getObjectManager()->create('\SalesIgniter\Rental\Model\StockManagement');
            $dataItem = $item->toArray([]);
            call_user_func_array([$stockManagementClass, 'updateStockFromGridData'], [$dataItem]);
            //$this->stock->updateStockFromGridData($item->toArray([]));
        }
    }

    protected function updateHotelMode()
    {
        $this->searchCriteriaBuilder->addFilter(new \Zend_Db_Expr('DATE_FORMAT(end_date,\'%H:%i:%s\')'), '00:00:00');
        $criteria = $this->searchCriteriaBuilder->create();
        $items = $this->reservationOrdersRepository->getList($criteria)->getItems();

        foreach ($items as $item) {
            $hasTimes = $this->helperCalendar->useTimes($item->getProductId());
            if (!$hasTimes && $this->helperCalendar->getHotelMode($item->getProductId()) === 0) {
                $item->setEndDate(date('Y-m-d', strtotime($item->getEndDate())).' 23:59:00');
                $item->setEndDateWithTurnover(date('Y-m-d', strtotime($item->getEndDateWithTurnover())).' 23:59:00');
                $item->setEndDateUseGrid(date('Y-m-d', strtotime($item->getEndDateUseGrid())).' 23:59:00');
                $item->save();
            }
        }
    }

    /**
     * @param int | array $oId
     * @param int         $deleteAll
     *
     * @return bool
     */
    protected function deleteOrders($oId = null, $deleteAll = 1)
    {
        if (!is_array($oId)) {
            $oId = [$oId];
        }
        $countCancelOrder = 0;
        $connection = $this->_resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
        $showTables = $connection->fetchCol('show tables');
        $tblPrefix = (string) $this->deploymentConfig->get(
            ConfigOptionsListConstants::CONFIG_PATH_DB_PREFIX
        );

        $tblSalesOrder = $connection->getTableName($tblPrefix.'sales_order');
        $tblSalesCreditmemoComment = $connection->getTableName($tblPrefix.'sales_creditmemo_comment');
        $tblSalesCreditmemoItem = $connection->getTableName($tblPrefix.'sales_creditmemo_item');
        $tblSalesCreditmemo = $connection->getTableName($tblPrefix.'sales_creditmemo');
        $tblSalesCreditmemoGrid = $connection->getTableName($tblPrefix.'sales_creditmemo_grid');
        $tblSalesInvoiceComment = $connection->getTableName($tblPrefix.'sales_invoice_comment');
        $tblSalesInvoiceItem = $connection->getTableName($tblPrefix.'sales_invoice_item');
        $tblSalesInvoice = $connection->getTableName($tblPrefix.'sales_invoice');
        $tblSalesInvoiceGrid = $connection->getTableName($tblPrefix.'sales_invoice_grid');
        $tblQuoteAddressItem = $connection->getTableName($tblPrefix.'quote_address_item');
        $tblQuoteItemOption = $connection->getTableName($tblPrefix.'quote_item_option');
        $tblQuote = $connection->getTableName($tblPrefix.'quote');
        $tblQuoteAddress = $connection->getTableName($tblPrefix.'quote_address');
        $tblQuoteItem = $connection->getTableName($tblPrefix.'quote_item');
        $tblQuotePayment = $connection->getTableName($tblPrefix.'quote_payment');
        $tblQuoteShippingRate = $connection->getTableName($tblPrefix.'quote_shipping_rate');
        $tblQuoteIDMask = $connection->getTableName($tblPrefix.'quote_id_mask');
        $tblSalesShipmentComment = $connection->getTableName($tblPrefix.'sales_shipment_comment');
        $tblSalesShipmentItem = $connection->getTableName($tblPrefix.'sales_shipment_item');
        $tblSalesShipmentTrack = $connection->getTableName($tblPrefix.'sales_shipment_track');
        $tblSalesShipment = $connection->getTableName($tblPrefix.'sales_shipment');
        $tblSalesShipmentGrid = $connection->getTableName($tblPrefix.'sales_shipment_grid');
        $tblSalesOrderAddress = $connection->getTableName($tblPrefix.'sales_order_address');
        $tblSalesOrderItem = $connection->getTableName($tblPrefix.'sales_order_item');
        $tblSalesOrderPayment = $connection->getTableName($tblPrefix.'sales_order_payment');
        $tblSalesOrderStatusHistory = $connection->getTableName($tblPrefix.'sales_order_status_history');
        $tblSalesOrderGrid = $connection->getTableName($tblPrefix.'sales_order_grid');
        $tblReservationSalesOrder = $connection->getTableName($tblPrefix.'sirental_reservationorders');
        $tblLogQuote = $connection->getTableName($tblPrefix.'log_quote');
        $showTablesLog = $connection->fetchCol('SHOW TABLES LIKE ?', '%'.$tblLogQuote);
        $tblSalesOrderTax = $connection->getTableName($tblPrefix.'sales_order_tax');
        $orders = $this->orderRepository->getList($this->searchCriteriaBuilder->create());
        if ($deleteAll !== 1) {
            foreach ($orders as $order) {
                $orderId = $order->getId();
                if (!in_array($orderId, $oId)) {
                    continue;
                }
                $this->deleteOrder($orderId);
                if ($order->getIncrementId()) {
                    $incId = $order->getIncrementId();
                    if (in_array($tblSalesOrder, $showTables)) {
                        $result1 = $connection->fetchAll('SELECT quote_id FROM `'.$tblSalesOrder.'` WHERE entity_id='.$orderId);
                        $quoteId = (int) $result1[0]['quote_id'];
                    }
                    $connection->rawQuery('SET FOREIGN_KEY_CHECKS=0');
                    if (in_array($tblSalesCreditmemoComment, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesCreditmemoComment.'` WHERE parent_id IN (SELECT entity_id FROM `'.$tblSalesCreditmemo.'` WHERE order_id='.$orderId.')');
                    }
                    if (in_array('sales__creditmemo_item', $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesCreditmemoItem.'` WHERE parent_id IN (SELECT entity_id FROM `'.$tblSalesCreditmemo.'` WHERE order_id='.$orderId.')');
                    }
                    if (in_array($tblSalesCreditmemo, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesCreditmemo.'` WHERE order_id='.$orderId);
                    }
                    if (in_array($tblSalesCreditmemoGrid, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesCreditmemoGrid.'` WHERE order_id='.$orderId);
                    }
                    if (in_array($tblSalesInvoiceComment, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesInvoiceComment.'` WHERE parent_id IN (SELECT entity_id FROM `'.$tblSalesInvoice.'` WHERE order_id='.$orderId.')');
                    }
                    if (in_array($tblSalesInvoiceItem, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesInvoiceItem.'` WHERE parent_id IN (SELECT entity_id FROM `'.$tblSalesInvoice.'` WHERE order_id='.$orderId.')');
                    }
                    if (in_array($tblSalesInvoice, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesInvoice.'` WHERE order_id='.$orderId);
                    }
                    if (in_array($tblSalesInvoiceGrid, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesInvoiceGrid.'` WHERE order_id='.$orderId);
                    }
                    if ($quoteId) {
                        if (in_array($tblQuoteAddressItem, $showTables)) {
                            $connection->rawQuery('DELETE FROM `'.$tblQuoteAddressItem.'` WHERE parent_item_id IN (SELECT address_id FROM `'.$tblQuoteAddress.'` WHERE quote_id='.$quoteId.')');
                        }
                        if (in_array($tblQuoteShippingRate, $showTables)) {
                            $connection->rawQuery('DELETE FROM `'.$tblQuoteShippingRate.'` WHERE address_id IN (SELECT address_id FROM `'.$tblQuoteAddress.'` WHERE quote_id='.$quoteId.')');
                        }
                        if (in_array($tblQuoteIDMask, $showTables)) {
                            $connection->rawQuery('DELETE FROM `'.$tblQuoteIDMask.'` where quote_id='.$quoteId);
                        }
                        if (in_array($tblQuoteItemOption, $showTables)) {
                            $connection->rawQuery('DELETE FROM `'.$tblQuoteItemOption.'` WHERE item_id IN (SELECT item_id FROM `'.$tblQuoteItem.'` WHERE quote_id='.$quoteId.')');
                        }
                        if (in_array($tblQuote, $showTables)) {
                            $connection->rawQuery('DELETE FROM `'.$tblQuote.'` WHERE entity_id='.$quoteId);
                        }
                        if (in_array($tblQuoteAddress, $showTables)) {
                            $connection->rawQuery('DELETE FROM `'.$tblQuoteAddress.'` WHERE quote_id='.$quoteId);
                        }
                        if (in_array($tblQuoteItem, $showTables)) {
                            $connection->rawQuery('DELETE FROM `'.$tblQuoteItem.'` WHERE quote_id='.$quoteId);
                        }
                        if (in_array('sales__quotePayment', $showTables)) {
                            $connection->rawQuery('DELETE FROM `'.$tblQuotePayment.'` WHERE quote_id='.$quoteId);
                        }
                    }
                    if (in_array($tblSalesShipmentComment, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesShipmentComment.'` WHERE parent_id IN (SELECT entity_id FROM `'.$tblSalesShipment.'` WHERE order_id='.$orderId.')');
                    }
                    if (in_array($tblSalesShipmentItem, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesShipmentItem.'` WHERE parent_id IN (SELECT entity_id FROM `'.$tblSalesShipment.'` WHERE order_id='.$orderId.')');
                    }
                    if (in_array($tblSalesShipmentTrack, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesShipmentTrack.'` WHERE order_id IN (SELECT entity_id FROM `'.$tblSalesShipment.'` WHERE parent_id='.$orderId.')');
                    }
                    if (in_array($tblSalesShipment, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesShipment.'` WHERE order_id='.$orderId);
                    }
                    if (in_array($tblSalesShipmentGrid, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesShipmentGrid.'` WHERE order_id='.$orderId);
                    }
                    if (in_array($tblSalesOrder, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesOrder.'` WHERE entity_id='.$orderId);
                    }
                    if (in_array($tblSalesOrderAddress, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesOrderAddress.'` WHERE parent_id='.$orderId);
                    }
                    if (in_array($tblSalesOrderItem, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesOrderItem.'` WHERE order_id='.$orderId);
                    }
                    if (in_array($tblSalesOrderPayment, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesOrderPayment.'` WHERE parent_id='.$orderId);
                    }
                    if (in_array($tblSalesOrderStatusHistory, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesOrderStatusHistory.'` WHERE parent_id='.$orderId);
                    }
                    if (in_array($tblReservationSalesOrder, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblReservationSalesOrder.'` WHERE order_id='.$orderId);
                    }
                    if ($incId && in_array($tblSalesOrderGrid, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesOrderGrid.'` WHERE increment_id='.$incId);
                    }
                    if (in_array($tblSalesOrderTax, $showTables)) {
                        $connection->rawQuery('DELETE FROM `'.$tblSalesOrderTax.'` WHERE order_id='.$orderId);
                    }
                    if ($quoteId && $showTablesLog) {
                        $connection->rawQuery('DELETE FROM `'.$tblLogQuote.'` WHERE quote_id='.$quoteId);
                    }
                    $connection->rawQuery('SET FOREIGN_KEY_CHECKS=1');
                }
                ++$countCancelOrder;
            }
        } else {
            if (in_array($tblSalesCreditmemoComment, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesCreditmemoComment.'`');
            }
            if (in_array('sales__creditmemo_item', $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesCreditmemoItem.'`');
            }
            if (in_array($tblSalesCreditmemo, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesCreditmemo.'`');
            }
            if (in_array($tblSalesCreditmemoGrid, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesCreditmemoGrid.'`');
            }
            if (in_array($tblSalesInvoiceComment, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesInvoiceComment.'`');
            }
            if (in_array($tblSalesInvoiceItem, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesInvoiceItem.'`');
            }
            if (in_array($tblSalesInvoice, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesInvoice.'`');
            }
            if (in_array($tblSalesInvoiceGrid, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesInvoiceGrid.'`');
            }

            if (in_array($tblQuoteAddressItem, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblQuoteAddressItem.'`');
            }
            if (in_array($tblQuoteShippingRate, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblQuoteShippingRate.'`');
            }
            if (in_array($tblQuoteIDMask, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblQuoteIDMask.'`');
            }
            if (in_array($tblQuoteItemOption, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblQuoteItemOption.'`');
            }
            if (in_array($tblQuote, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblQuote.'`');
            }
            if (in_array($tblQuoteAddress, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblQuoteAddress.'`');
            }
            if (in_array($tblQuoteItem, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblQuoteItem.'`');
            }
            if (in_array('sales__quotePayment', $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblQuotePayment.'`');
            }

            if (in_array($tblSalesShipmentComment, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesShipmentComment.'`');
            }
            if (in_array($tblSalesShipmentItem, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesShipmentItem.'`');
            }
            if (in_array($tblSalesShipmentTrack, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesShipmentTrack.'`');
            }
            if (in_array($tblSalesShipment, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesShipment.'`');
            }
            if (in_array($tblSalesShipmentGrid, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesShipmentGrid.'`');
            }
            if (in_array($tblSalesOrder, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesOrder.'`');
            }
            if (in_array($tblSalesOrderAddress, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesOrderAddress.'`');
            }
            if (in_array($tblSalesOrderItem, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesOrderItem.'`');
            }
            if (in_array($tblSalesOrderPayment, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesOrderPayment.'`');
            }
            if (in_array($tblSalesOrderStatusHistory, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesOrderStatusHistory.'`');
            }
            if (in_array($tblReservationSalesOrder, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblReservationSalesOrder.'`');
            }
            if (in_array($tblSalesOrderGrid, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesOrderGrid.'`');
            }
            if (in_array($tblSalesOrderTax, $showTables)) {
                $connection->rawQuery('DELETE FROM `'.$tblSalesOrderTax.'`');
            }
            if ($showTablesLog) {
                $connection->rawQuery('DELETE FROM `'.$tblLogQuote.'`');
            }
            $connection->rawQuery('SET FOREIGN_KEY_CHECKS=1');
            $this->resetInventoryAll();
        }

        return false;
    }

    protected function invoiceOrder($orderId)
    {
        $order = $this->orderRepository->get($orderId);
        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->register();
            $invoice->save();
            $transactionSave = $this->transaction->addObject(
                $invoice
            )->addObject(
                $invoice->getOrder()
            );
            $transactionSave->save();
            //$this->invoiceSender->send($invoice);
            //send notification code
            $order->addStatusHistoryComment(
                __('Notified customer about invoice #%1.', $invoice->getId())
            )
                ->setIsCustomerNotified(true)
                ->save();
        }
    }

    /**
     * Create Order On Your Store.
     *
     * @param $orderItems
     *
     * @return int $orderId
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     * @internal param array $orderData
     */
    protected function createOrder($orderItems)
    {
        $orderData = [
            'currency_id' => 'USD',
            'email' => 'cristian1@itwebexperts.com', //buyer email id
            'shipping_address' => [
                'firstname' => 'Cristian', //address Details
                'lastname' => 'Arcu',
                'street' => 'xxxxx',
                'city' => 'Casa grande',
                'country_id' => 'US',
                'region' => 'CA',
                'postcode' => '93455',
                'telephone' => '52332',
                'fax' => '32423',
                'save_in_address_book' => 0,
            ],
        ];
        $orderData['items'] = $orderItems;

        //init the store id and website id @todo pass from array
        $store = $this->storeManager->getStore();
        $websiteId = $this->storeManager->getStore()->getWebsiteId();
        //init the customer
        $customer = $this->customerFactory->create();
        $customer->setWebsiteId($websiteId);
        $customer->loadByEmail($orderData['email']); // load customet by email address
        //check the customer
        if (!$customer->getEntityId()) {
            //If not available then create this customer
            $customer->setWebsiteId($websiteId)
                ->setStore($store)
                ->setFirstname($orderData['shipping_address']['firstname'])
                ->setLastname($orderData['shipping_address']['lastname'])
                ->setEmail($orderData['email'])
                ->setPassword($orderData['email']);
            $customer->save();
        }
        //init the quote
        $cartId = $this->cartManagementInterface->createEmptyCart();
        $cart = $this->cartRepositoryInterface->get($cartId);
        $cart->setStore($store);
        // if you have already buyer id then you can load customer directly
        $customer = $this->customerRepository->getById($customer->getEntityId());
        $cart->setCurrency();
        $cart->assignCustomer($customer); //Assign quote to customer
        //add items in quote
        foreach ($orderData['items'] as $item) {
            $product = $this->productFactory->create()->load($item['product_id']);

            $buyRequest = new \Magento\Framework\DataObject();
            $buyRequest->setData($item);
            $cart->addProduct(
                $product,
                $buyRequest
            );
        }
        //Set Address to quote @todo add section in order data for separate billing and handle it
        $cart->getBillingAddress()->addData($orderData['shipping_address']);
        $cart->getShippingAddress()->addData($orderData['shipping_address']);
        // Collect Rates and Set Shipping & Payment Method
        $this->shippingRate
            ->setCode('freeshipping_freeshipping')
            ->getPrice(1);
        $shippingAddress = $cart->getShippingAddress();
        //@todo set in order data
        $shippingAddress->setCollectShippingRates(true)
            ->collectShippingRates()
            ->setShippingMethod('flatrate_flatrate'); //shipping method
        $cart->getShippingAddress()->addShippingRate($this->shippingRate);
        $cart->setPaymentMethod('checkmo'); //payment method

        $cart->setInventoryProcessed(false);
        // Set sales order payment
        $cart->getPayment()->importData(['method' => 'checkmo']);
        // Collect total and save
        $cart->collectTotals();
        // Submit the quote and create the order
        $cart->save();
        $cart = $this->cartRepositoryInterface->get($cart->getId());
        $orderId = $this->cartManagementInterface->placeOrder($cart->getId());

        return $orderId;
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('salesigniter:runTest')
            ->setDescription('Run Test')
            ->addArgument(self::TEST_ID, InputArgument::OPTIONAL, 'Test Id');
        //->addArgument(self::ATTRIBUTE_VALUE, InputArgument::REQUIRED, 'Attribute Value');
        parent::configure();
    }

    private function getXmlAsArray()
    {
        $dom = new \DOMDocument();
        $xmlFile = __DIR__.'/../../etc/tests.xml';
        $dom->loadXML(file_get_contents($xmlFile));
        $root = $dom->documentElement;
        $output = $this->xmlToArray($root);
        $output['@root'] = $root->tagName;

        return $output;
    }

    private function xmlToArray($node)
    {
        $output = [];
        switch ($node->nodeType) {
            case XML_CDATA_SECTION_NODE:
            case XML_TEXT_NODE:
                $output = trim($node->textContent);
                break;
            case XML_ELEMENT_NODE:
                for ($i = 0, $m = $node->childNodes->length; $i < $m; ++$i) {
                    $child = $node->childNodes->item($i);
                    $v = $this->xmlToArray($child);
                    if (isset($child->tagName)) {
                        $t = $child->tagName;
                        if (!array_key_exists($t, $output)) {
                            $output[$t] = [];
                        }
                        $output[$t][] = $v;
                    } elseif ($v || $v === '0') {
                        $output = (string) $v;
                    }
                }
                if ($node->attributes->length && !is_array($output)) { //Has attributes but isn't an array
                    $output = ['@content' => $output]; //Change output into an array.
                }
                if (is_array($output)) {
                    if ($node->attributes->length) {
                        $a = [];
                        foreach ($node->attributes as $attrName => $attrNode) {
                            $a[$attrName] = (string) $attrNode->value;
                        }
                        $output['@attributes'] = $a;
                    }
                    foreach ($output as $t => $v) {
                        if (is_array($v) && count($v) === 1 && $t !== '@attributes') {
                            $output[$t] = $v[0];
                        }
                    }
                }
                break;
        }

        return $output;
    }

    /**
     * @param $productConfig
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\StateException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function updateProduct($productConfig)
    {
        if (array_key_exists('id', $productConfig)) {
            $productObject = $this->productRepository->getById($productConfig['id']);

            foreach ($productConfig as $key => $value) {
                if ($key === 'id') {
                    continue;
                }
                /** @var array $valueInterpreteds */
                $valueInterpreteds = null;
                eval("\$valueInterpreteds = {$value};");

                foreach ($valueInterpreteds as $valueInterpreted) {
                    if ($key !== 'sirent_price' && $key !== 'sirent_excluded_dates' && $key !== 'sirent_serial_numbers') {
                        $stores = $valueInterpreted['store_id'];
                        if ($key === 'sirent_quantity') {
                            $stores = 'any';
                        }
                        $storeIds = $this->helperRental->getStoreIdsForCurrentWebsite();
                        $storeIdsArr = $this->helperRental->getStoreIdsForCurrentWebsite();
                        $storeIdsArr[] = 0;
                        if ($stores !== 'any') {
                            $storeIds = [(int) $stores];
                        } else {
                            $storeIds[] = 0;
                        }
                        foreach ($storeIdsArr as $storeId) {
                            if (in_array($storeId, $storeIds)) {
                                $productObject->setData($key, $valueInterpreted['value']);
                                $this->attributeAction->updateAttributes(
                                    [$productConfig['id']],
                                    [$key => $valueInterpreted['value']],
                                    $storeId
                                );
                            }
                            //$this->productRepository->save($productObject);
                        }
                    } else {
                        $productObject->setData($key, $valueInterpreted);
                        $this->productRepository->save($productObject);
                    }
                }
            }
        }
    }

    /**
     * @param $test
     *
     * @return mixed
     */
    private function functionCall($test)
    {
        $className = $test['class'];
        $functionName = $test['name'];
        $parameters = null;

        eval("\$parameters = {$test['arguments']};");

        $class = $this->getObjectManager()->create($className);

        return call_user_func_array([$class, $functionName], $parameters);
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \RuntimeException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Start here ');
        $this->initMembers();

        $testArray = $this->getXmlAsArray();

        $testId = $input->getArgument(self::TEST_ID);

        $this->registry->register('isSecureArea', 'true');

        $testSuite = $this->getTests($testArray, 'testsuite', $testId);
        $fixtures = $this->getTests($testArray, 'fixtures');
        foreach ($testSuite as $suite) {
            $updated = $this->updateProductsByFixtures($fixtures);

            $tests = $this->getTests($suite, 'test');
            $fixturesTest = $this->getTests($suite, 'fixtures');
            $updFixtures = $this->updateProductsByFixtures($fixturesTest);

            $output->writeln('Executing Tests from suite: '.$suite['id'].' ');
            foreach ($tests as $test) {
                if ($test['type'] == 'orderItemIds') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $orderItemsArray = $this->getOrderItemIds($parameters[0]);
                    if (array_key_exists('return', $test)) {
                        ${$test['return']['@attributes']['variable']} = $orderItemsArray;
                    }
                }
                if ($test['type'] == 'shipOrderItem') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->shipOrderItem($parameters[0]['order_item_ids'], $parameters[0]['qtys']);
                }
                if ($test['type'] == 'returnOrderItem') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->returnOrderItem($parameters[0]['order_item_ids'], $parameters[0]['qtys']);
                }
                if ($test['type'] == 'createCreditMemo') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->creditMemoOrder($parameters[0]['order_id'], $parameters[0]['qtys']);
                }
                if ($test['type'] == 'createInvoice') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->invoiceOrder($parameters[0]);
                }
                if ($test['type'] == 'showInventory') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->showInventory($parameters[0]);
                }
                if ($test['type'] == 'regenerateInventory') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->regenerateInventory($parameters[0]);
                }
                if ($test['type'] == 'createProducts') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->createProducts($parameters[0]);
                }
                if ($test['type'] == 'createCategory') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->createCategories($parameters[0]['id'], $parameters[0]['name']);
                }
                if ($test['type'] == 'deleteAllProducts') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->deleteAllProducts($parameters);
                }
                if ($test['type'] == 'removeProductAttributes') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->removeProductEavAttributes($parameters);
                }
                if ($test['type'] == 'removeEavAttributes') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->removeEavAttribute($parameters);
                }
                if ($test['type'] == 'removeCustomerAttributes') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->removeCustomerEavAttributes($parameters);
                }

                if ($test['type'] == 'createOrder') {
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $orderId = $this->createOrder($parameters);
                    if (array_key_exists('return', $test)) {
                        ${$test['return']['@attributes']['variable']} = $orderId;
                    }
                }
                if ($test['type'] == 'deleteAllOrders') {
                    $output->writeln('delete all orders ');
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->deleteOrders($parameters[0], 1);
                }
                if ($test['type'] == 'deleteOrders') {
                    $output->writeln('delete orders ');
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->deleteOrders($parameters, 0);
                }
                if ($test['type'] == 'updateHotelMode') {
                    $output->writeln('update hotel mode for all products ');
                    $parameters = [];
                    eval("\$parameters = {$test['arguments']};");
                    $this->updateHotelMode($parameters);
                }
                if ($test['type'] == 'resetInventoryAll') {
                    $output->writeln('reset inventory ');
                    $this->resetInventoryAll();
                }
                if ($test['type'] == 'function') {
                    $id = $test['id'];
                    $className = $test['class'];
                    $functionName = $test['name'];
                    $parameters = null;
                    eval("\$parameters = {$test['arguments']};");
                    $class = $this->getObjectManager()->create($className);

                    if (array_key_exists('return', $test)) {
                        ${$test['return']['@attributes']['variable']} = call_user_func_array([$class, $functionName], $parameters);
                        $result = ${$test['return']['@attributes']['variable']};
                    } else {
                        $result = call_user_func_array([$class, $functionName], $parameters);
                    }
                    if (array_key_exists('assertion', $test)) {
                        switch ($test['assertion']['@attributes']['type']) {
                            case 'equal':
                                $resultFunc = null;
                                $output->writeln('Starting eval for test: '.$id);

                                eval("\$resultFunc = {$test['assertion']['@content']};");

                                if ($result == $resultFunc) {
                                    $output->writeln('Assertions for test: '.$id.' is true');
                                } else {
                                    var_dump($result);
                                    $output->writeln('Assertions for test: '.$id.' is false');
                                }
                                break;
                            case 'no_equal':
                                break;
                            default:
                                break;
                        }
                    }
                    $output->writeln('Test: '.$id.' has been executed');
                }
            }
        }
        $this->registry->unregister('isSecureArea');              //unset secure area
    }

    /**
     * @param $testArray
     * @param $type
     * @param $testId
     *
     * @return array
     */
    protected function getTests($testArray, $type, $testId = null)
    {
        /** @var array $testSuite */
        $testSuite = [];
        if (array_key_exists(0, $testArray[$type])) {
            if (null !== $testId) {
                $key = array_search($testId, array_column($testArray[$type], 'id'));

                if ($key !== false) {
                    $testSuite[] = $testArray[$type][$key];
                }
            } else {
                $testSuite = $testArray[$type];
            }
        } elseif (null === $testId || (null !== $testId && $testArray[$type]['id'] === $testId)) {
            $testSuite[] = $testArray[$type];
        }

        return $testSuite;
    }

    private function creditMemoOrder($orderId, $qtys)
    {
        $data['qtys'] = $qtys;
        $order = $this->orderRepository->get($orderId);
        $creditmemo = $this->creditMemoFactory->createByOrder($order, $data);
        $creditmemo->save();
    }

    private function shipOrderItem($orderItemIds, $qtys)
    {
        //$data['qty_actions'] = $qtys;
        $massShipper = $this->getObjectManager()->create('\SalesIgniter\Rental\Controller\Adminhtml\Send\MassSend');
        $reservationsFactory = $this->getObjectManager()->create('\SalesIgniter\Rental\Model\ResourceModel\ReservationOrders\CollectionFactory');
        $collectionReservations = $reservationsFactory->create();
        $collectionReservations->filterByMain()->load();
        $reservationsIdsArray = [];
        $qtyActions = [];
        foreach ($collectionReservations as $reservation) {
            if (in_array($reservation->getOrderItemId(), $orderItemIds)) {
                $reservationsIdsArray[] = $reservation->getId();
                if (array_key_exists('qty_shipped', $qtys)) {
                    $qtyActions['qty_shipped'][$reservation->getId()] = $qtys['qty_shipped'][$reservation->getOrderItemId()];
                }
                if (array_key_exists('serials_shipped', $qtys)) {
                    $qtyActions['serials_shipped'][$reservation->getId()] = $qtys['serials_shipped'][$reservation->getOrderItemId()];
                }
            }
        }
        $massShipper->massShip($reservationsIdsArray, $qtyActions);
    }

    private function returnOrderItem($orderItemIds, $qtys)
    {
        $massShipper = $this->getObjectManager()->create('\SalesIgniter\Rental\Controller\Adminhtml\Returns\MassReturn');
        $reservationsFactory = $this->getObjectManager()->create('\SalesIgniter\Rental\Model\ResourceModel\ReservationOrders\CollectionFactory');
        $collectionReservations = $reservationsFactory->create();
        $collectionReservations->filterByMain()->load();
        $reservationsIdsArray = [];
        $qtyActions = [];
        foreach ($collectionReservations as $reservation) {
            if (in_array($reservation->getOrderItemId(), $orderItemIds)) {
                $reservationsIdsArray[] = $reservation->getId();
                if (array_key_exists('qty_returned', $qtys)) {
                    $qtyActions['qty_returned'][$reservation->getId()] = $qtys['qty_returned'][$reservation->getOrderItemId()];
                }
                if (array_key_exists('serials_returned', $qtys)) {
                    $qtyActions['serials_returned'][$reservation->getId()] = $qtys['serials_returned'][$reservation->getOrderItemId()];
                }
            }
        }
        $massShipper->massReturn($reservationsIdsArray, $qtyActions);
    }

    private function getOrderItemIds($orderId)
    {
        $order = $this->orderRepository->get($orderId);
        $orderItems = [];
        foreach ($order->getItems() as $item) {
            $orderItems[] = $item->getItemId();
        }

        return $orderItems;
    }

    protected function createUserRole()
    {
        $role = $this->roleFactory->create();
        $role->setName('YourRoleName')//Set Role Name Which you want to create
        ->setPid(0)//set parent role id of your role
        ->setRoleType(RoleGroup::ROLE_TYPE)
            ->setUserType(UserContextInterface::USER_TYPE_ADMIN);
        $role->save();
        /* Now we set that which resources we allow to this role */
        $resource = ['Magento_Backend::admin',
            'Magento_Sales::sales',
            'Magento_Sales::create',
            'Magento_Sales::actions_view', //you will use resource id which you want tp allow
            'Magento_Sales::cancel',
        ];
        /* Array of resource ids which we want to allow this role*/
        $this->rulesFactory->create()->setRoleId($role->getId())->setResources($resource)->saveRel();
    }
}
