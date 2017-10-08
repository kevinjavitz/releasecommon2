<?php

namespace SalesIgniter\Common\Model\Source;

use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Price By Date types ids to their names (day, week, month, etc)
 */
class TypeDayToYear extends \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource
{

    protected $_optionFactory;

    /**
     * Initialize dependencies.
     *
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute\OptionFactory $optionFactory
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param \Magento\Framework\Api\FilterBuilder $filterBuilder
     */
    public function __construct(
        \Magento\Eav\Model\ResourceModel\Entity\Attribute\OptionFactory $optionFactory
    ) {
        $this->_optionFactory = $optionFactory;
    }

    /**
     * Retrieve all period types
     *
     * @param bool $withEmpty
     * @return array
     */
    public function getAllOptions($withEmpty = true)
    {
        $this->_options[] = [
            'value' => 1,
            'label' => __('Day'),
        ];
        $this->_options[] = [
            'value' => 2,
            'label' => __('Week'),
        ];
        $this->_options[] = [
            'value' => 3,
            'label' => __('Month'),
        ];
        $this->_options[] = [
            'value' => 4,
            'label' => __('Year'),
        ];


        return $this->_options;
    }

}