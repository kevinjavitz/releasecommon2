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
 * Deletes a module's core_config_data rows, in every scope, as its own system.xml declares them.
 * See SystemConfigReader for how a section, a group or a single field is decided to be its own.
 */
class ConfigRemover
{
    /** @var ModuleFiles */
    private $files;

    /** @var SystemConfigReader */
    private $reader;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param ModuleFiles $files
     * @param SystemConfigReader $reader
     * @param LoggerInterface $logger
     */
    public function __construct(ModuleFiles $files, SystemConfigReader $reader, LoggerInterface $logger)
    {
        $this->files = $files;
        $this->reader = $reader;
        $this->logger = $logger;
    }

    /**
     * @param string $moduleName
     * @return array{prefixes: string[], exact: string[]}
     */
    public function plan(string $moduleName): array
    {
        $xml = $this->files->read($moduleName, 'etc/adminhtml/system.xml');
        if ($xml === null) {
            return ['prefixes' => [], 'exact' => []];
        }
        $otherGroups = [];
        foreach ($this->files->readOthers($moduleName, 'etc/adminhtml/system.xml') as $otherXml) {
            try {
                $otherGroups[] = $this->reader->read($otherXml)['groups'];
            } catch (\InvalidArgumentException $e) {
                continue;
            }
        }
        return $this->reader->pathsToDelete(
            $this->reader->read($xml),
            $otherGroups ? array_merge(...$otherGroups) : []
        );
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param string $moduleName
     * @return int rows deleted
     */
    public function remove(SchemaSetupInterface $setup, string $moduleName): int
    {
        $plan = $this->plan($moduleName);
        $connection = $setup->getConnection();
        $table = $setup->getTable('core_config_data');
        $deleted = 0;
        foreach ($plan['prefixes'] as $prefix) {
            $deleted += $connection->delete(
                $table,
                $connection->quoteInto('path LIKE ?', addcslashes($prefix, '\\%_') . '%')
            );
        }
        foreach (array_chunk($plan['exact'], 500) as $chunk) {
            $deleted += $connection->delete($table, ['path IN (?)' => $chunk]);
        }
        $this->logger->info(sprintf('[%s uninstall] deleted %d core_config_data rows', $moduleName, $deleted));
        return $deleted;
    }
}
