<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace SalesIgniter\Common\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * Shows period type like daily, weekly, monthly, yearly
 * instead of period id for price by date
 */
class Type extends Column
{

    /**
     * Constructor
     *
     * @param ContextInterface $context
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        PriceCurrencyInterface $priceFormatter,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                switch($item[$this->getData('name')]) {
                    case 1:
                        $item[$this->getData('name')] = __('Daily');
                        break;
                    case 2:
                        $item[$this->getData('name')] = __('Weekly');
                        break;
                    case 3:
                        $item[$this->getData('name')] = __('Monthly');
                        break;
                    case 4:
                        $item[$this->getData('name')] = __('Yearly');
                        break;
                    case 5:
                        $item[$this->getData('name')] = __('Never');
                }
            }
        }

        return $dataSource;
    }
}