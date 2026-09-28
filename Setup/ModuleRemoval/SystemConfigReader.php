<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Setup\ModuleRemoval;

/**
 * Reads the configuration paths one module's etc/adminhtml/system.xml declares.
 *
 * Three kinds of answer, because a module can own a whole section, only some groups inside
 * somebody else's section, or only single fields inside somebody else's group:
 *  - sections: declared here WITH a <tab>, i.e. this module created the section
 *    (SalesIgniter_Rental's `salesigniter_rental`; the add-ons only add groups to it);
 *  - groups: every group path, nested ones included (`section/group/subgroup`);
 *  - fields: every field's stored path, honouring <config_path>.
 */
class SystemConfigReader
{
    /**
     * @param string $xml contents of a system.xml
     * @return array{sections: string[], groups: string[], fields: string[]}
     */
    public function read(string $xml): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml);
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \InvalidArgumentException('system.xml could not be parsed');
        }

        $out = ['sections' => [], 'groups' => [], 'fields' => []];
        foreach ($dom->getElementsByTagName('section') as $section) {
            $sectionId = $section->getAttribute('id');
            if ($sectionId === '') {
                continue;
            }
            foreach ($section->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->nodeName === 'tab') {
                    $out['sections'][] = $sectionId;
                }
                if ($child instanceof \DOMElement && $child->nodeName === 'group') {
                    $this->readGroup($child, $sectionId, $out);
                }
            }
        }
        foreach ($out as $key => $values) {
            $out[$key] = array_values(array_unique($values));
        }
        return $out;
    }

    /**
     * @param \DOMElement $group
     * @param string $parentPath
     * @param array $out
     * @return void
     */
    private function readGroup(\DOMElement $group, string $parentPath, array &$out): void
    {
        $id = $group->getAttribute('id');
        if ($id === '') {
            return;
        }
        $path = $parentPath . '/' . $id;
        $out['groups'][] = $path;
        foreach ($group->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->nodeName === 'group') {
                $this->readGroup($child, $path, $out);
            } elseif ($child->nodeName === 'field' && $child->getAttribute('id') !== '') {
                $configPath = null;
                foreach ($child->childNodes as $fieldChild) {
                    if ($fieldChild instanceof \DOMElement && $fieldChild->nodeName === 'config_path') {
                        $configPath = trim($fieldChild->textContent);
                    }
                }
                $out['fields'][] = $configPath ?: $path . '/' . $child->getAttribute('id');
            }
        }
    }

    /**
     * Turn one module's declarations into what to delete, given what every other module declares.
     *
     * @param array{sections: string[], groups: string[], fields: string[]} $mine
     * @param string[] $otherGroups group paths declared by any other module
     * @return array{prefixes: string[], exact: string[]} prefixes end in "/"
     */
    public function pathsToDelete(array $mine, array $otherGroups): array
    {
        $prefixes = [];
        foreach ($mine['sections'] as $section) {
            $prefixes[] = $section . '/';
        }
        $others = array_flip($otherGroups);
        foreach ($mine['groups'] as $group) {
            if (!isset($others[$group]) && !$this->isCovered($group . '/', $prefixes)) {
                $prefixes[] = $group . '/';
            }
        }
        $exact = [];
        foreach ($mine['fields'] as $field) {
            if (!$this->isCovered($field, $prefixes)) {
                $exact[] = $field;
            }
        }
        return ['prefixes' => $prefixes, 'exact' => array_values(array_unique($exact))];
    }

    /**
     * @param string $path
     * @param string[] $prefixes
     * @return bool
     */
    private function isCovered(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (strpos($path, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}
