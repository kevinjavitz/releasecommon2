<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Setup\ModuleRemoval;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;

/**
 * Reads a module's own etc/*.xml files, and the same file from every other registered module.
 *
 * During `module:uninstall` the module's code is still on disk: Magento removes the data first
 * and runs `composer remove` afterwards, so the files that created the data are there to say what
 * the data is.
 */
class ModuleFiles
{
    /** @var ComponentRegistrarInterface */
    private $componentRegistrar;

    /**
     * @param ComponentRegistrarInterface $componentRegistrar
     */
    public function __construct(ComponentRegistrarInterface $componentRegistrar)
    {
        $this->componentRegistrar = $componentRegistrar;
    }

    /**
     * @param string $moduleName e.g. SalesIgniter_Rental
     * @param string $relativePath e.g. etc/db_schema.xml
     * @return string|null null when the module or the file does not exist
     */
    public function read(string $moduleName, string $relativePath): ?string
    {
        $path = $this->componentRegistrar->getPath(ComponentRegistrar::MODULE, $moduleName);
        return $path === null ? null : $this->readFile($path . '/' . $relativePath);
    }

    /**
     * The same file from every other registered module that has one.
     *
     * @param string $exceptModule
     * @param string $relativePath
     * @param string[] $skip further module names to leave out
     * @return array<string, string> module name => file contents
     */
    public function readOthers(string $exceptModule, string $relativePath, array $skip = []): array
    {
        $out = [];
        $skip = array_flip($skip);
        foreach ($this->componentRegistrar->getPaths(ComponentRegistrar::MODULE) as $moduleName => $path) {
            if ($moduleName === $exceptModule || isset($skip[$moduleName])) {
                continue;
            }
            $contents = $this->readFile($path . '/' . $relativePath);
            if ($contents !== null) {
                $out[$moduleName] = $contents;
            }
        }
        return $out;
    }

    /**
     * Every registered module that depends on $moduleName, directly or through others, by the
     * <sequence> in its etc/module.xml. Magento refuses to uninstall a module while a module that
     * depends on it stays installed, so what a dependent declares never has to be protected: the
     * dependent is being uninstalled in the same command (or already was).
     *
     * @param string $moduleName
     * @return string[]
     */
    public function dependentsOf(string $moduleName): array
    {
        $dependsOn = [];
        foreach ($this->componentRegistrar->getPaths(ComponentRegistrar::MODULE) as $name => $path) {
            $xml = $this->readFile($path . '/etc/module.xml');
            if ($xml === null) {
                continue;
            }
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadXML($xml);
            libxml_use_internal_errors($previous);
            if (!$loaded) {
                continue;
            }
            foreach ($dom->getElementsByTagName('sequence') as $sequence) {
                foreach ($sequence->getElementsByTagName('module') as $module) {
                    $dependsOn[$module->getAttribute('name')][] = $name;
                }
            }
        }
        $dependents = [];
        $queue = [$moduleName];
        while ($queue) {
            foreach ($dependsOn[array_shift($queue)] ?? [] as $dependent) {
                if ($dependent !== $moduleName && !isset($dependents[$dependent])) {
                    $dependents[$dependent] = true;
                    $queue[] = $dependent;
                }
            }
        }
        return array_keys($dependents);
    }

    /**
     * @param string $file
     * @return string|null
     */
    private function readFile(string $file): ?string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $contents = file_get_contents($file);
        return $contents === false ? null : $contents;
    }
}
