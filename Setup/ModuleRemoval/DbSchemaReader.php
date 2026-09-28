<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Setup\ModuleRemoval;

/**
 * Reads one module's etc/db_schema.xml into plain arrays. No database, no Magento objects.
 *
 * Magento's `module:uninstall --remove-data` does NOT drop what a module declares in
 * db_schema.xml: `ModuleUninstaller::uninstallData()` only calls the module's Setup\Uninstall
 * class and reverts data patches that implement PatchRevertableInterface, and the module's code
 * (and with it its db_schema_whitelist.json) is gone before the next setup:upgrade could notice.
 * So an Uninstall class has to drop its own tables and columns, and this is where it learns
 * which ones they are - from the same file that created them, so the list cannot drift.
 *
 * A table the module CREATES is told apart from one it only EXTENDS by the primary key: the
 * creator declares `<constraint xsi:type="primary">`; a module adding columns to `sales_order`
 * or to another module's table does not. Every table in every Sales Igniter db_schema.xml
 * follows that rule (checked 2026-09-24), and SchemaRemover adds a second guard on top.
 */
class DbSchemaReader
{
    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * @param string $xml contents of a db_schema.xml
     * @return array<string, array{
     *     primary: bool,
     *     columns: string[],
     *     foreign: array<int, array{column: string, referenceTable: string, referenceColumn: string}>
     * }> table name (without prefix) => what this file declares on it
     */
    public function read(string $xml): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \InvalidArgumentException('db_schema.xml could not be parsed');
        }

        $tables = [];
        foreach ($dom->documentElement->childNodes as $table) {
            if (!$table instanceof \DOMElement || $table->nodeName !== 'table') {
                continue;
            }
            $name = $table->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $entry = $tables[$name] ?? ['primary' => false, 'columns' => [], 'foreign' => []];
            foreach ($table->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                if ($child->nodeName === 'column' && $child->getAttribute('name') !== '') {
                    $entry['columns'][] = $child->getAttribute('name');
                    continue;
                }
                if ($child->nodeName !== 'constraint') {
                    continue;
                }
                $type = $child->getAttributeNS(self::XSI, 'type');
                if ($type === 'primary') {
                    $entry['primary'] = true;
                } elseif ($type === 'foreign') {
                    $entry['foreign'][] = [
                        'column' => $child->getAttribute('column'),
                        'referenceTable' => $child->getAttribute('referenceTable'),
                        'referenceColumn' => $child->getAttribute('referenceColumn'),
                    ];
                }
            }
            $entry['columns'] = array_values(array_unique($entry['columns']));
            $tables[$name] = $entry;
        }

        return $tables;
    }
}
