<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Setup\ModuleRemoval;

use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Psr\Log\LoggerInterface;

/**
 * The rows Magento keeps ABOUT a module, as opposed to the module's own data: which of its patches
 * have run, which admin roles may use its ACL resources, its queued cron jobs, its flags, and
 * widget instances of its widget types.
 *
 * patch_list is the one that matters most. Magento deletes the setup_module row itself, but not
 * the patch_list rows of patches that cannot be reverted - and a reinstall would then skip every
 * data patch the module has, leaving its attributes and ACL grants uninstalled.
 */
class ModuleRecordsRemover
{
    /** @var ModuleFiles */
    private $files;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param ModuleFiles $files
     * @param LoggerInterface $logger
     */
    public function __construct(ModuleFiles $files, LoggerInterface $logger)
    {
        $this->files = $files;
        $this->logger = $logger;
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param string $moduleName
     * @param string $flagPrefix flag_code prefix of the module's flags; '' for none
     * @return array<string, int> what was deleted, by kind
     */
    public function remove(SchemaSetupInterface $setup, string $moduleName, string $flagPrefix = ''): array
    {
        $counts = [
            'widget_instance' => $this->removeWidgetInstances($setup, $moduleName),
            'authorization_rule' => $this->removeAclRules($setup, $moduleName),
            'cron_schedule' => $this->removeCronJobs($setup, $moduleName),
            'flag' => $flagPrefix === '' ? 0 : $this->removeFlags($setup, $flagPrefix),
            'patch_list' => $this->removePatchHistory($setup, $moduleName),
        ];
        foreach ($counts as $kind => $count) {
            $this->logger->info(sprintf('[%s uninstall] deleted %d %s rows', $moduleName, $count, $kind));
        }
        return $counts;
    }

    /**
     * Patch names are class names without the leading backslash. Compared in PHP, not with LIKE:
     * backslash is LIKE's escape character, and a pattern like 'Vendor\\Module\\%' silently
     * matches nothing.
     *
     * Patches implementing PatchRevertableInterface are left alone: ModuleUninstaller reverts them
     * after this class runs, and PatchHistory::revertPatchFromHistory() throws for a patch whose
     * row is already gone.
     *
     * @param SchemaSetupInterface $setup
     * @param string $moduleName
     * @return int
     */
    public function removePatchHistory(SchemaSetupInterface $setup, string $moduleName): int
    {
        $connection = $setup->getConnection();
        $table = $setup->getTable('patch_list');
        if (!$connection->isTableExists($table)) {
            return 0;
        }
        $namespace = str_replace('_', '\\', $moduleName) . '\\';
        $names = [];
        foreach ($connection->fetchCol($connection->select()->from($table, ['patch_name'])) as $name) {
            $name = ltrim((string)$name, '\\');
            if (strpos($name, $namespace) !== 0) {
                continue;
            }
            if (class_exists($name) && is_subclass_of($name, PatchRevertableInterface::class)) {
                continue;
            }
            $names[] = $name;
        }
        $deleted = 0;
        foreach (array_chunk($names, 500) as $chunk) {
            $deleted += $connection->delete($table, ['patch_name IN (?)' => $chunk]);
        }
        return $deleted;
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param string $moduleName
     * @return int
     */
    public function removeAclRules(SchemaSetupInterface $setup, string $moduleName): int
    {
        $connection = $setup->getConnection();
        $table = $setup->getTable('authorization_rule');
        if (!$connection->isTableExists($table)) {
            return 0;
        }
        return $connection->delete(
            $table,
            $connection->quoteInto('resource_id LIKE ?', addcslashes($moduleName . '::', '\\%_') . '%')
        );
    }

    /**
     * Job codes come from the module's own etc/crontab.xml.
     *
     * @param SchemaSetupInterface $setup
     * @param string $moduleName
     * @return int
     */
    public function removeCronJobs(SchemaSetupInterface $setup, string $moduleName): int
    {
        $xml = $this->files->read($moduleName, 'etc/crontab.xml');
        $connection = $setup->getConnection();
        $table = $setup->getTable('cron_schedule');
        if ($xml === null || !$connection->isTableExists($table)) {
            return 0;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return 0;
        }
        $jobs = [];
        foreach ($dom->getElementsByTagName('job') as $job) {
            if ($job->getAttribute('name') !== '') {
                $jobs[] = $job->getAttribute('name');
            }
        }
        return $jobs ? $connection->delete($table, ['job_code IN (?)' => array_unique($jobs)]) : 0;
    }

    /**
     * @param SchemaSetupInterface $setup
     * @param string $flagPrefix
     * @return int
     */
    public function removeFlags(SchemaSetupInterface $setup, string $flagPrefix): int
    {
        $connection = $setup->getConnection();
        $table = $setup->getTable('flag');
        if (!$connection->isTableExists($table)) {
            return 0;
        }
        return $connection->delete(
            $table,
            $connection->quoteInto('flag_code LIKE ?', addcslashes($flagPrefix, '\\%_') . '%')
        );
    }

    /**
     * Widget instances whose type is one of the module's classes cannot render once the class is
     * gone, and the layout updates they own are applied on every page they were placed on. This
     * mirrors Magento\Widget\Model\ResourceModel\Widget\Instance::_beforeDelete()/_afterDelete():
     * the layout_update rows first, then the instance (its page rows cascade).
     *
     * @param SchemaSetupInterface $setup
     * @param string $moduleName
     * @return int instances deleted
     */
    public function removeWidgetInstances(SchemaSetupInterface $setup, string $moduleName): int
    {
        $connection = $setup->getConnection();
        $instanceTable = $setup->getTable('widget_instance');
        if (!$connection->isTableExists($instanceTable)) {
            return 0;
        }
        $namespace = str_replace('_', '\\', $moduleName) . '\\';
        $ids = [];
        foreach ($connection->fetchPairs(
            $connection->select()->from($instanceTable, ['instance_id', 'instance_type'])
        ) as $id => $type) {
            if (strpos(ltrim((string)$type, '\\'), $namespace) === 0) {
                $ids[] = (int)$id;
            }
        }
        if (!$ids) {
            return 0;
        }
        $layoutUpdateIds = $connection->fetchCol(
            $connection->select()
                ->from(['p' => $setup->getTable('widget_instance_page')], [])
                ->joinInner(
                    ['l' => $setup->getTable('widget_instance_page_layout')],
                    'l.page_id = p.page_id',
                    ['layout_update_id']
                )
                ->where('p.instance_id IN (?)', $ids)
        );
        if ($layoutUpdateIds) {
            $connection->delete($setup->getTable('layout_update'), ['layout_update_id IN (?)' => $layoutUpdateIds]);
        }
        return $connection->delete($instanceTable, ['instance_id IN (?)' => $ids]);
    }
}
