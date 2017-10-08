<?php

namespace SalesIgniter\Common\Model\Source;

use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Product source model used for edit form select list of products.
 */
class Product extends \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource
{

    protected $_productRepository;

    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    protected $_searchCriteriaBuilder;

    /**
     * @var \Magento\Framework\Api\FilterBuilder
     */
    protected $_filterBuilder;

    /**
     * @var \Magento\Eav\Model\ResourceModel\Entity\Attribute\OptionFactory
     */
    protected $_optionFactory;

    protected $filterGroupBuilder;

    /**
     * Initialize dependencies.
     *
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute\OptionFactory $optionFactory
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param \Magento\Framework\Api\FilterBuilder $filterBuilder
     */
    public function __construct(
        \Magento\Eav\Model\ResourceModel\Entity\Attribute\OptionFactory $optionFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $ProductRepository,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Framework\Api\Search\FilterGroupBuilder $filterGroupBuilder,
        \Magento\Framework\Api\FilterBuilder $filterBuilder
    ) {
        $this->_optionFactory = $optionFactory;
        $this->_productRepository = $ProductRepository;
        $this->_filterBuilder = $filterBuilder;
        $this->filterGroupBuilder = $filterGroupBuilder;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
    }

    /**
     * Retrieve all rental products as options.
     *
     * @param bool $withEmpty
     * @return array
     */
    public function getAllOptions($withEmpty = true)
    {
        if (!$this->_options) {
            $searchCriteria = $this->_searchCriteriaBuilder->create();

            $filter = $this->_filterBuilder
                ->setField('type_id')
                ->setConditionType('eq')
                ->setValue('sirent')
                ->create();

            //add our filter(s) to a group
            $filter_group = $this->filterGroupBuilder
                ->addFilter($filter)
                ->create();

            //add the group(s) to the search criteria object
            $searchCriteria->setFilterGroups([$filter_group]);

            $searchResults = $this->_productRepository->getList($searchCriteria);
            foreach ($searchResults->getItems() as $product) {
                $this->_options[] = [
                    'value' => $product->getId(),
                    'label' => $product->getName(),
                ];
            }
        }

        if ($withEmpty) {
            if (!$this->_options) {
                return [['value' => '0', 'label' => __('None')]];
            } else {
                return array_merge([['value' => '0', 'label' => __('None')]], $this->_options);
            }
        }
        return $this->_options;
    }

}