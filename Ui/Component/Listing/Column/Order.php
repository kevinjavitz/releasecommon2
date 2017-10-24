<?php

namespace SalesIgniter\Common\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Shows order increment id in admin grids instead of order id.
 */
class Order extends Column
{
    /**
     * Escaper.
     *
     * @var \Magento\Framework\Escaper
     */
    protected $escaper;

    /**
     * System store.
     *
     * @var SystemStore
     */
    protected $systemStore;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;
    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;
    /**
     * @var \Magento\Framework\UrlInterface
     */
    private $urlBuilder;

    /**
     * Constructor.
     *
     * @param ContextInterface                            $context
     * @param UiComponentFactory                          $uiComponentFactory
     * @param Escaper                                     $escaper
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Framework\UrlInterface             $urlBuilder
     * @param array                                       $components
     * @param array                                       $data
     *
     * @internal param \SalesIgniter\Common\Ui\Component\Listing\Column\SystemStore $systemStore
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        Escaper $escaper,
        OrderRepositoryInterface $orderRepository,
        \Magento\Framework\UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->escaper = $escaper;
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->orderRepository = $orderRepository;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Prepare Data Source.
     *
     * @param array $dataSource
     *
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if ((int) $item[$this->getData('name')] > 0) {
                    try {
                        $order = $this->orderRepository->get((int) $item[$this->getData('name')]);
                        $orderIncrementId = $order->getIncrementId();
                        $orderId = $order->getId();
                    } catch (\Exception $e) {
                        $orderIncrementId = 'deleted order(run regenerateInventory)';
                        $orderId = '0';
                    }

                    $item[$this->getData('name')] = '<a href="'.$this->urlBuilder->getUrl('sales/order/view', ['order_id' => $orderId]).'">'.$orderIncrementId.'</a>';
                } else {
                    $item[$this->getData('name')] = '0';
                }
                // backup product_id to product_idbackup in case another column still needs the column id
                $item[$this->getData('name').'backup'] = $item[$this->getData('name')];
            }
        }

        return $dataSource;
    }
}
