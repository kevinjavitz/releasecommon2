<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Setup\ModuleRemoval;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\SchemaSetupInterface;
use Psr\Log\LoggerInterface;

/**
 * Everything a Sales Igniter module's Setup\Uninstall has to do for
 * `bin/magento module:uninstall --remove-data`, in the one order that is safe to repeat.
 *
 * Shared by SalesIgniter_Rental and its add-ons (which require releaserental2 >= 1.2.206), so each
 * add-on's Uninstall class is a few lines naming its module and its attribute codes.
 *
 * Order: refuse first if another module's table points into ours (nothing has changed yet); then
 * attributes, configuration and bookkeeping rows; the schema last. Every step skips what is already
 * gone, so a run that failed half way can simply be run again.
 */
class ModuleDataRemover
{
    /** @var SchemaRemover */
    private $schema;

    /** @var AttributeRemover */
    private $attributes;

    /** @var ConfigRemover */
    private $config;

    /** @var ModuleRecordsRemover */
    private $records;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param SchemaRemover $schema
     * @param AttributeRemover $attributes
     * @param ConfigRemover $config
     * @param ModuleRecordsRemover $records
     * @param LoggerInterface $logger
     */
    public function __construct(
        SchemaRemover $schema,
        AttributeRemover $attributes,
        ConfigRemover $config,
        ModuleRecordsRemover $records,
        LoggerInterface $logger
    ) {
        $this->schema = $schema;
        $this->attributes = $attributes;
        $this->config = $config;
        $this->records = $records;
        $this->logger = $logger;
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param string $moduleName e.g. SalesIgniter_WaiverDamage
     * @param string[] $attributeCodes product attributes the module's setup code created
     * @param string[] $legacyTables tables an older version created and db_schema.xml no longer declares
     * @param string $flagPrefix flag_code prefix of the module's flags; '' for none
     * @param array<string, string[]> $legacyColumns columns an older version added and no longer declares
     * @return void
     * @throws LocalizedException
     */
    public function remove(
        SchemaSetupInterface $setup,
        string $moduleName,
        array $attributeCodes = [],
        array $legacyTables = [],
        string $flagPrefix = '',
        array $legacyColumns = []
    ): void {
        $this->withForeignKeyChecks(
            $setup,
            function () use ($setup, $moduleName, $attributeCodes, $legacyTables, $flagPrefix, $legacyColumns) {
                $this->schema->assertRemovable($setup, $moduleName, $legacyTables);
                $this->attributes->remove($setup, $moduleName, $attributeCodes);
                $this->config->remove($setup, $moduleName);
                $this->records->remove($setup, $moduleName, $flagPrefix);
                $this->schema->remove($setup, $moduleName, $legacyTables, $legacyColumns);
            }
        );
        $this->logger->info(sprintf('[%s uninstall] data removed', $moduleName));
    }

    /**
     * The check remove() starts with, for an Uninstall class that changes something of its own
     * first (SalesIgniter_Rental converts its products) and must not do that on a store where
     * remove() would then refuse.
     *
     * @param SchemaSetupInterface $setup
     * @param string $moduleName
     * @param string[] $legacyTables
     * @return void
     * @throws LocalizedException
     */
    public function assertRemovable(SchemaSetupInterface $setup, string $moduleName, array $legacyTables = []): void
    {
        $this->schema->assertRemovable($setup, $moduleName, $legacyTables);
    }

    /**
     * Run $work with FOREIGN_KEY_CHECKS on, and put back whatever the session had.
     *
     * On, because deleting an eav_attribute row only cascades into the product value tables when
     * the checks are on, and because a drop that would orphan a constraint should fail rather than
     * succeed quietly. Forced, because the connection is shared by every module Magento uninstalls
     * in the same command, and SchemaSetupInterface::startSetup() - which an Uninstall class
     * elsewhere may call and not undo - turns them off.
     *
     * @param SchemaSetupInterface $setup
     * @param callable $work
     * @return mixed
     */
    public function withForeignKeyChecks(SchemaSetupInterface $setup, callable $work)
    {
        $connection = $setup->getConnection();
        $previous = (int)$connection->fetchOne('SELECT @@SESSION.FOREIGN_KEY_CHECKS');
        $connection->query('SET FOREIGN_KEY_CHECKS = 1');
        try {
            return $work();
        } finally {
            $connection->query('SET FOREIGN_KEY_CHECKS = ' . ($previous ? 1 : 0));
        }
    }
}
