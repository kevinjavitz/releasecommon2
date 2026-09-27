<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Setup\ModuleRemoval;

use Magento\Framework\Setup\SchemaSetupInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns the products of a product type that is about to disappear into Magento's own types, so
 * they (and the orders that sold them) still open. Products are never deleted.
 *
 * Which type: the rule Magento itself uses when an admin toggles "This item has weight"
 * (Catalog\Model\Product\TypeTransitionManager) - a product with a weight is `simple`, one without
 * is `virtual`. A weight counts when any store scope holds a value above zero.
 *
 * Every converted product is also DISABLED, in every scope. A rental product's own price is
 * usually 0 (the rental module prices from its own tables), so leaving one enabled would put a
 * free item on sale with no availability check. The admin re-enables the ones to sell after
 * giving them a price.
 */
class ProductTypeConverter
{
    public const TYPE_SIMPLE = 'simple';
    public const TYPE_VIRTUAL = 'virtual';
    private const STATUS_DISABLED = 2;
    private const BATCH = 1000;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param string $moduleName for the log
     * @param string $fromType
     * @return array{simple: int, virtual: int}
     */
    public function convert(SchemaSetupInterface $setup, string $moduleName, string $fromType): array
    {
        $connection = $setup->getConnection();
        $entityTable = $setup->getTable('catalog_product_entity');
        // Adobe Commerce keys the value tables on row_id (content staging); Open Source on entity_id.
        $linkField = $connection->tableColumnExists($entityTable, 'row_id') ? 'row_id' : 'entity_id';

        $products = $connection->fetchPairs(
            $connection->select()
                ->from($entityTable, ['entity_id', $linkField])
                ->where('type_id = ?', $fromType)
        );
        $result = [self::TYPE_SIMPLE => 0, self::TYPE_VIRTUAL => 0];
        if (!$products) {
            return $result;
        }

        $weightId = $this->productAttributeId($setup, 'weight');
        $statusId = $this->productAttributeId($setup, 'status');
        $decimalTable = $setup->getTable('catalog_product_entity_decimal');
        $intTable = $setup->getTable('catalog_product_entity_int');

        foreach (array_chunk($products, self::BATCH, true) as $chunk) {
            $linkIds = array_values($chunk);
            $weighted = [];
            if ($weightId !== null) {
                $weighted = $connection->fetchCol(
                    $connection->select()
                        ->from($decimalTable, [$linkField])
                        ->where('attribute_id = ?', $weightId)
                        ->where($linkField . ' IN (?)', $linkIds)
                        ->where('value > 0')
                        ->distinct(true)
                );
            }
            $weighted = array_flip(array_map('strval', $weighted));
            $simple = [];
            $virtual = [];
            foreach ($chunk as $entityId => $linkId) {
                if (isset($weighted[(string)$linkId])) {
                    $simple[] = (int)$entityId;
                } else {
                    $virtual[] = (int)$entityId;
                }
            }
            if ($simple) {
                $result[self::TYPE_SIMPLE] += $connection->update(
                    $entityTable,
                    ['type_id' => self::TYPE_SIMPLE],
                    ['entity_id IN (?)' => $simple, 'type_id = ?' => $fromType]
                );
            }
            if ($virtual) {
                $result[self::TYPE_VIRTUAL] += $connection->update(
                    $entityTable,
                    ['type_id' => self::TYPE_VIRTUAL],
                    ['entity_id IN (?)' => $virtual, 'type_id = ?' => $fromType]
                );
            }

            if ($statusId !== null) {
                $connection->update(
                    $intTable,
                    ['value' => self::STATUS_DISABLED],
                    ['attribute_id = ?' => $statusId, $linkField . ' IN (?)' => $linkIds]
                );
                $rows = [];
                foreach ($linkIds as $linkId) {
                    $rows[] = [
                        'attribute_id' => $statusId,
                        'store_id' => 0,
                        $linkField => (int)$linkId,
                        'value' => self::STATUS_DISABLED,
                    ];
                }
                $connection->insertOnDuplicate($intTable, $rows, ['value']);
            }
        }

        $this->logger->warning(sprintf(
            '[%s uninstall] converted %d %s products to simple and %d to virtual, and disabled them all; '
            . 'give each one a price and a quantity before enabling it again, and run bin/magento indexer:reindex',
            $moduleName,
            $result[self::TYPE_SIMPLE],
            $fromType,
            $result[self::TYPE_VIRTUAL]
        ));
        return $result;
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param string $code
     * @return int|null
     */
    private function productAttributeId(SchemaSetupInterface $setup, string $code): ?int
    {
        $connection = $setup->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from(['a' => $setup->getTable('eav_attribute')], ['attribute_id'])
                ->joinInner(['t' => $setup->getTable('eav_entity_type')], 't.entity_type_id = a.entity_type_id', [])
                ->where('t.entity_type_code = ?', 'catalog_product')
                ->where('a.attribute_code = ?', $code)
        );
        return $id === false || $id === null ? null : (int)$id;
    }
}
