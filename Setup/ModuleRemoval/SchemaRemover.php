<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Setup\ModuleRemoval;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Setup\SchemaSetupInterface;
use Psr\Log\LoggerInterface;

/**
 * Drops the tables a module created and the columns it added to other modules' tables, as its
 * own etc/db_schema.xml declares them.
 *
 * Why this exists at all: `bin/magento module:uninstall --remove-data` never drops declarative
 * schema. It calls Setup\Uninstall and reverts PatchRevertableInterface data patches, then runs
 * `composer remove`, which takes db_schema.xml and db_schema_whitelist.json away with the code -
 * so no later setup:upgrade can tell those tables ever belonged to anybody.
 *
 * Rules, all derived from files, none from a hand list. "Another module" below means any registered
 * module that does not depend on this one (ModuleFiles::dependentsOf()): a dependent is uninstalled
 * with or before this module, so it never protects anything.
 *  - a table this module declares WITH a primary key is its own and is dropped whole; if another
 *    module declares it too, it is left alone entirely and the fact is logged;
 *  - on any other table it declares, the columns it declares are dropped, except a column another
 *    module declares too (SalesIgniter_Rentalinventory's `source_code` on the rental tables is
 *    also declared by SalesIgniter_Rental, so uninstalling the MSI add-on alone keeps it);
 *  - $legacyTables and $legacyColumns (what an older version created and no longer declares) are
 *    dropped too, under the same guard.
 *
 * Every name goes through SchemaSetupInterface::getTable(), so a table prefix is honoured.
 * Foreign keys are dropped explicitly before the tables, so the drop order never matters and
 * nothing depends on FOREIGN_KEY_CHECKS; and a foreign key from a table this module does NOT
 * own into one it does stops everything before the first change (see assertRemovable()).
 */
class SchemaRemover
{
    /** @var ModuleFiles */
    private $files;

    /** @var DbSchemaReader */
    private $reader;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param ModuleFiles $files
     * @param DbSchemaReader $reader
     * @param LoggerInterface $logger
     */
    public function __construct(ModuleFiles $files, DbSchemaReader $reader, LoggerInterface $logger)
    {
        $this->files = $files;
        $this->reader = $reader;
        $this->logger = $logger;
    }

    /**
     * What remove() would drop, as unprefixed names.
     *
     * @param string $moduleName
     * @param string[] $legacyTables
     * @param array<string, string[]> $legacyColumns table => columns
     * @return array{tables: string[], columns: array<string, string[]>}
     */
    public function plan(string $moduleName, array $legacyTables = [], array $legacyColumns = []): array
    {
        $xml = $this->files->read($moduleName, 'etc/db_schema.xml');
        $declared = $xml === null ? [] : $this->reader->read($xml);
        foreach ($legacyColumns as $table => $columnNames) {
            if (!isset($declared[$table]) || !$declared[$table]['primary']) {
                $declared[$table]['primary'] = false;
                $declared[$table]['columns'] = array_merge($declared[$table]['columns'] ?? [], $columnNames);
            }
        }

        // What every module that does not depend on this one declares is never ours to drop.
        $protected = [];
        $others = $this->files->readOthers($moduleName, 'etc/db_schema.xml', $this->files->dependentsOf($moduleName));
        foreach ($others as $otherModule => $otherXml) {
            foreach ($this->reader->read($otherXml) as $table => $definition) {
                $protected[$table]['modules'][] = $otherModule;
                $protected[$table]['columns'] = array_merge($protected[$table]['columns'] ?? [], $definition['columns']);
            }
        }

        $tables = [];
        $columns = [];
        foreach ($declared as $table => $definition) {
            if ($definition['primary']) {
                if (isset($protected[$table])) {
                    $this->logger->warning(sprintf(
                        '[%s uninstall] kept table %s: %s declare(s) it too',
                        $moduleName,
                        $table,
                        implode(', ', $protected[$table]['modules'])
                    ));
                    continue;
                }
                $tables[] = $table;
                continue;
            }
            $own = array_values(array_unique(array_diff($definition['columns'], $protected[$table]['columns'] ?? [])));
            if ($own) {
                $columns[$table] = $own;
            }
        }
        foreach ($legacyTables as $table) {
            if (!isset($protected[$table]) && !isset($declared[$table])) {
                $tables[] = $table;
            }
        }

        return ['tables' => array_values(array_unique($tables)), 'columns' => $columns];
    }

    /**
     * Refuse, before anything is changed, when a table this module does not own has a foreign key
     * into one it is about to drop. With FOREIGN_KEY_CHECKS off MySQL would drop the parent anyway
     * and leave the child's constraint dangling (the next setup:upgrade then fails in
     * SchemaBuilder::processReferenceKeys()); with them on the drop fails half way through.
     *
     * @param SchemaSetupInterface $setup
     * @param string $moduleName
     * @param string[] $legacyTables
     * @return void
     * @throws LocalizedException
     */
    public function assertRemovable(SchemaSetupInterface $setup, string $moduleName, array $legacyTables = []): void
    {
        $owned = $this->existingOwnedTables($setup, $this->plan($moduleName, $legacyTables)['tables']);
        $incoming = $this->incomingForeignKeys($setup->getConnection(), array_values($owned));
        if (!$incoming) {
            return;
        }
        $lines = [];
        foreach ($incoming as $row) {
            $lines[] = sprintf('%s.%s -> %s', $row['TABLE_NAME'], $row['CONSTRAINT_NAME'], $row['REFERENCED_TABLE_NAME']);
        }
        throw new LocalizedException(__(
            'Cannot remove the data of %1: tables that belong to other modules still reference its tables (%2). '
            . 'Uninstall the modules that own those tables first, or in the same command before %1.',
            $moduleName,
            implode(', ', $lines)
        ));
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param string $moduleName
     * @param string[] $legacyTables
     * @param array<string, string[]> $legacyColumns table => columns
     * @return void
     * @throws LocalizedException
     */
    public function remove(
        SchemaSetupInterface $setup,
        string $moduleName,
        array $legacyTables = [],
        array $legacyColumns = []
    ): void {
        $this->assertRemovable($setup, $moduleName, $legacyTables);

        $plan = $this->plan($moduleName, $legacyTables, $legacyColumns);
        $connection = $setup->getConnection();
        $owned = $this->existingOwnedTables($setup, $plan['tables']);

        // Constraints first, so the tables can then go in any order.
        foreach ($owned as $realName) {
            foreach ($connection->getForeignKeys($realName) as $foreignKey) {
                $connection->dropForeignKey($realName, $foreignKey['FK_NAME']);
            }
        }
        foreach ($owned as $realName) {
            $connection->dropTable($realName);
            $this->logger->info(sprintf('[%s uninstall] dropped table %s', $moduleName, $realName));
        }

        foreach ($plan['columns'] as $table => $columnNames) {
            $realName = $setup->getTable($table);
            if (!$connection->isTableExists($realName)) {
                continue;
            }
            foreach ($columnNames as $column) {
                if ($connection->tableColumnExists($realName, $column)) {
                    // Pdo\Mysql::dropColumn() drops a foreign key on the column first.
                    $connection->dropColumn($realName, $column);
                    $this->logger->info(sprintf('[%s uninstall] dropped column %s.%s', $moduleName, $realName, $column));
                }
            }
        }
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param string[] $tables unprefixed
     * @return array<string, string> unprefixed => real name, only those that exist
     */
    private function existingOwnedTables(SchemaSetupInterface $setup, array $tables): array
    {
        $connection = $setup->getConnection();
        $out = [];
        foreach ($tables as $table) {
            $realName = $setup->getTable($table);
            if ($connection->isTableExists($realName)) {
                $out[$table] = $realName;
            }
        }
        return $out;
    }

    /**
     * Foreign keys from tables outside $tables into tables inside it.
     *
     * @param AdapterInterface $connection
     * @param string[] $tables real (prefixed) names
     * @return array<int, array{TABLE_NAME: string, CONSTRAINT_NAME: string, REFERENCED_TABLE_NAME: string}>
     */
    private function incomingForeignKeys(AdapterInterface $connection, array $tables): array
    {
        if (!$tables) {
            return [];
        }
        $select = $connection->select()
            ->from(
                ['k' => 'information_schema.KEY_COLUMN_USAGE'],
                ['TABLE_NAME', 'CONSTRAINT_NAME', 'REFERENCED_TABLE_NAME']
            )
            ->where('k.TABLE_SCHEMA = DATABASE()')
            ->where('k.REFERENCED_TABLE_SCHEMA = DATABASE()')
            ->where('k.REFERENCED_TABLE_NAME IN (?)', $tables)
            ->where('k.TABLE_NAME NOT IN (?)', $tables)
            ->distinct(true);
        return $connection->fetchAll($select);
    }
}
