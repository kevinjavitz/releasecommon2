<?php

namespace SalesIgniter\Common\Ui\Component\Listing\Column;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Shows product name in admin grids instead of product id
 */
class Product extends Column
{
    /**
     * Escaper
     *
     * @var \Magento\Framework\Escaper
     */
    protected $escaper;

    /**
     * System store
     *
     * @var SystemStore
     */
    protected $systemStore;

    /**
     * @var \Magento\Catalog\Api\ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * Constructor
     *
     * @param ContextInterface                                $context
     * @param UiComponentFactory                              $uiComponentFactory
     * @param Escaper                                         $escaper
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     * @param array                                           $components
     * @param array                                           $data
     *
     * @internal param \SalesIgniter\Common\Ui\Component\Listing\Column\SystemStore $systemStore
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        Escaper $escaper,
        ProductRepositoryInterface $productRepository,
        array $components = [],
        array $data = []
    ) {
        $this->escaper = $escaper;
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->productRepository = $productRepository;
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     *
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                $product = $this->productRepository->getById((int)$item['product_id']);
                // backup product_id to product_idbackup in case another column still needs the column id
                //$item[$this->getData('name') . 'backup'] = $item[$this->getData('name')];
                $item[$this->getData('name')] = $product->getName();
            }
        }

        return $dataSource;
    }
}
