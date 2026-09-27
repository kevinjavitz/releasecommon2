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
 * Removes product attributes, and the attribute groups that held nothing else.
 *
 * Deleting the eav_attribute row is all Magento's own EavSetup::removeAttribute() does; the
 * foreign keys cascade it into catalog_eav_attribute, the set/group assignments, every
 * catalog_product_entity_* value row, options and labels. That cascade only happens with
 * FOREIGN_KEY_CHECKS on (ModuleDataRemover guarantees it), so the rows that matter are also
 * deleted explicitly first: a database that once lost attributes with the checks off - the old
 * salesigniter:Uninstall ran with them off - is full of assignment rows pointing at attributes that
 * no longer exist (296 on the dev database), and those must not keep a "Rental" group alive.
 *
 * An attribute an admin created (is_user_defined = 1) is never removed, even when its code is on
 * the list: the rental module's codes have been reused by hand on real installs
 * (`sirent_emailextra`), and an old code the module dropped years ago may since have been
 * recreated by somebody else.
 */
class AttributeRemover
{
    private const PRODUCT_ENTITY = 'catalog_product';

    /** Rows keyed on attribute_id that the foreign keys would cascade, deleted explicitly too. */
    private const DEPENDENT_TABLES = [
        'eav_entity_attribute',
        'catalog_eav_attribute',
        'eav_attribute_label',
        'eav_attribute_option',
        'catalog_product_entity_datetime',
        'catalog_product_entity_decimal',
        'catalog_product_entity_int',
        'catalog_product_entity_text',
        'catalog_product_entity_varchar',
    ];

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
     * @param string[] $codes
     * @return string[] the codes actually removed
     */
    public function remove(SchemaSetupInterface $setup, string $moduleName, array $codes): array
    {
        $entityTypeId = $this->productEntityTypeId($setup);
        if ($entityTypeId === null || !$codes) {
            return [];
        }
        $connection = $setup->getConnection();
        $attributeTable = $setup->getTable('eav_attribute');
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($attributeTable, ['attribute_id', 'attribute_code', 'is_user_defined'])
                ->where('entity_type_id = ?', $entityTypeId)
                ->where('attribute_code IN (?)', array_values(array_unique($codes)))
        );
        $ids = [];
        $removed = [];
        foreach ($rows as $row) {
            if ((int)$row['is_user_defined'] === 1) {
                $this->logger->warning(sprintf(
                    '[%s uninstall] kept product attribute %s: it was created in the admin, not by the module',
                    $moduleName,
                    $row['attribute_code']
                ));
                continue;
            }
            $ids[] = (int)$row['attribute_id'];
            $removed[] = $row['attribute_code'];
        }
        if (!$ids) {
            return [];
        }

        // Remember which groups held these attributes, to remove the ones left empty.
        $groupIds = $connection->fetchCol(
            $connection->select()
                ->from($setup->getTable('eav_entity_attribute'), ['attribute_group_id'])
                ->where('attribute_id IN (?)', $ids)
                ->distinct(true)
        );

        foreach (self::DEPENDENT_TABLES as $dependent) {
            $table = $setup->getTable($dependent);
            if ($connection->isTableExists($table)) {
                $connection->delete($table, ['attribute_id IN (?)' => $ids]);
            }
        }
        $connection->delete($attributeTable, ['attribute_id IN (?)' => $ids]);
        $this->logger->info(sprintf(
            '[%s uninstall] removed %d product attributes: %s',
            $moduleName,
            count($removed),
            implode(', ', $removed)
        ));

        if ($groupIds) {
            // "Empty" means no attribute that still exists; see the class comment about orphans.
            $stillUsed = $connection->fetchCol(
                $connection->select()
                    ->from(['ea' => $setup->getTable('eav_entity_attribute')], ['attribute_group_id'])
                    ->joinInner(['a' => $attributeTable], 'a.attribute_id = ea.attribute_id', [])
                    ->where('ea.attribute_group_id IN (?)', $groupIds)
                    ->distinct(true)
            );
            $empty = array_values(array_diff($groupIds, $stillUsed));
            if ($empty) {
                $connection->delete($setup->getTable('eav_entity_attribute'), ['attribute_group_id IN (?)' => $empty]);
                $connection->delete($setup->getTable('eav_attribute_group'), ['attribute_group_id IN (?)' => $empty]);
                $this->logger->info(sprintf(
                    '[%s uninstall] removed %d attribute groups left empty',
                    $moduleName,
                    count($empty)
                ));
            }
        }

        return $removed;
    }

    /**
     * Take a product type out of every attribute's apply_to list (the rental module added `sirent`
     * to weight, tax_class_id and others so the product form would show them).
     *
     * An apply_to holding nothing but that type is left alone: an empty apply_to means "every
     * type", which would widen the attribute instead of narrowing it.
     *
     * @param SchemaSetupInterface $setup
     * @param string $moduleName
     * @param string $typeId
     * @return int attributes changed
     */
    public function removeTypeFromApplyTo(SchemaSetupInterface $setup, string $moduleName, string $typeId): int
    {
        $connection = $setup->getConnection();
        $table = $setup->getTable('catalog_eav_attribute');
        if (!$connection->isTableExists($table)) {
            return 0;
        }
        $changed = 0;
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($table, ['attribute_id', 'apply_to'])
                ->where('apply_to LIKE ?', '%' . addcslashes($typeId, '\\%_') . '%')
        );
        foreach ($rows as $attributeId => $applyTo) {
            $types = array_filter(array_map('trim', explode(',', (string)$applyTo)), 'strlen');
            $kept = array_values(array_filter($types, function ($type) use ($typeId) {
                return $type !== $typeId;
            }));
            if (count($kept) === count($types) || !$kept) {
                continue;
            }
            $connection->update($table, ['apply_to' => implode(',', $kept)], ['attribute_id = ?' => (int)$attributeId]);
            $changed++;
        }
        $this->logger->info(sprintf('[%s uninstall] took %s out of apply_to on %d attributes', $moduleName, $typeId, $changed));
        return $changed;
    }

    /**
     * @param SchemaSetupInterface $setup
     * @return int|null
     */
    private function productEntityTypeId(SchemaSetupInterface $setup): ?int
    {
        $connection = $setup->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($setup->getTable('eav_entity_type'), ['entity_type_id'])
                ->where('entity_type_code = ?', self::PRODUCT_ENTITY)
        );
        return $id === false || $id === null ? null : (int)$id;
    }
}
