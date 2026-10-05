<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Architecture;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

/**
 * setup:di:compile scans every PHP file under an ENABLED module's directory, recursively (only the top-level
 * Test/ is left out). SubModules/* sit inside SalesIgniter_Common's directory, so they are scanned on every
 * store, also where SalesIgniter_CommonPay<Gateway> is not registered because its gateway is missing
 * (registration.php). For each class the compiler require_once's the file and reads the constructor and the
 * parents: every typed constructor parameter must be a loadable class ("Impossible to process constructor
 * argument"), and a missing parent class, interface or trait is a fatal error.
 *
 * So no class in this package may extend, implement or use (trait) a gateway type, or take one in its
 * constructor (type or default value). Gateway types may appear in method bodies, in method signatures other
 * than the constructor (plugin methods are never resolved by the compiler) and in SubModules/<Gw>/etc/*.xml,
 * which Magento reads only where the sub-module is registered. Gateway objects are taken from the object
 * manager by class name (StripeStrategy, StripeGateway, TokenBaseStrategy, MollieStrategy).
 *
 * Found in 1.2.58 before its release (2026-10-05): StripeStrategy, StripeGateway, TokenBaseStrategy and
 * MollieStrategy took gateway types in their constructors, MitTransactionPart implemented a Mollie interface.
 *
 * The same holds for optional Magento core modules. A store may remove modules such as Magento_InstantPurchase
 * (together with Magento_Paypal, Braintree and Payment Services), so a Magento type the compiler reads must come
 * from a module every store with SalesIgniter_Common has: one in etc/module.xml's sequence, or one of their
 * Composer requirements, recursively (alwaysInstalledMagentoNamespaces()). Found the same day: VaultStrategy
 * took Magento_InstantPurchase's IntegrationsManager in its constructor; it now gets it by name on first use.
 */
class SubModulesCompileSafeTest extends TestCase
{
    /** the gateways the sub-modules integrate, and the SDKs they bring */
    private const GATEWAY_NAMESPACES = [
        'StripeIntegration\\', 'Stripe\\', 'PayPal\\Braintree\\', 'Braintree\\', 'Adyen\\', 'Mollie\\', 'ParadoxLabs\\',
    ];

    /** @var list<string>|null cache of alwaysInstalledMagentoNamespaces() */
    private static $alwaysInstalled;

    private const BUILTIN = [
        'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object',
        'parent', 'self', 'static', 'string', 'true', 'void',
    ];

    public function testNoClassNamesAGatewayTypeWhereTheCompilerReadsIt(): void
    {
        $hits = [];
        $files = $this->scannedFiles();
        foreach ($files as $relative => $path) {
            foreach (self::compilerVisibleTypes((string)file_get_contents($path)) as $class => $types) {
                foreach ($types as $type) {
                    if (self::isGatewayType($type)) {
                        $hits[] = $relative . ': ' . $class . ' needs ' . $type;
                    }
                }
            }
        }
        self::assertArrayHasKey('SubModules/Stripe/Model/StripeStrategy.php', $files, 'the scan reaches SubModules/');
        self::assertSame([], $hits);
    }

    /** No class in the package names a type of an optional Magento module (outside module.xml's dependencies) where the compiler reads it. */
    public function testNoClassNamesAnOptionalCoreModuleTypeWhereTheCompilerReadsIt(): void
    {
        $hits = [];
        $files = $this->scannedFiles();
        foreach ($files as $relative => $path) {
            foreach (self::compilerVisibleTypes((string)file_get_contents($path)) as $class => $types) {
                foreach ($types as $type) {
                    if (self::isOptionalCoreType($type)) {
                        $hits[] = $relative . ': ' . $class . ' needs ' . $type;
                    }
                }
            }
        }
        self::assertArrayHasKey('Model/Payment/OffSession/Strategy/VaultStrategy.php', $files, 'the scan reaches the off-session layer');
        self::assertSame([], $hits);
    }

    /** What counts as optional: Magento modules outside the dependencies of etc/module.xml's sequence. */
    public function testOptionalCoreModulesAreTheOnesNoDeclaredDependencyNeeds(): void
    {
        foreach ([
            'Magento\\InstantPurchase\\PaymentMethodIntegration\\IntegrationsManager',
            '\\Magento\\Paypal\\Model\\Config',
            'Magento\\PaymentServicesPaypal\\Model\\Config',
            'Magento\\InstantPurchase\\Model\\QuoteManagement\\PaymentConfigurationFactory',
        ] as $optional) {
            self::assertTrue(self::isOptionalCoreType($optional), $optional);
        }
        foreach ([
            'Magento\\Framework\\ObjectManagerInterface',
            'Magento\\Vault\\Api\\PaymentTokenManagementInterface',
            'Magento\\Checkout\\Model\\Session\\Proxy',
            'Magento\\Quote\\Model\\QuoteFactory',
            'Magento\\Customer\\Api\\CustomerRepositoryInterface',
            'Magento\\Catalog\\Api\\ProductRepositoryInterface',
            'SalesIgniter\\Common\\Model\\Payment\\OffSession\\Strategy\\VaultStrategy',
            'Mollie\\Payment\\Model\\Mollie',
        ] as $required) {
            self::assertFalse(self::isOptionalCoreType($required), $required);
        }
    }

    /**
     * The same reads as setup:di:compile (load the class, ClassReader::getConstructor() and getParents()) for
     * every class under SubModules/ and in the off-session layer, in a child PHP where every gateway namespace
     * and every optional Magento module is unloadable: the store without any gateway and without
     * Magento_InstantPurchase, on any dev site. (Those directories only: elsewhere constructors may take classes
     * that the compiler generates first, such as factories, which plain Composer autoloading cannot load.)
     */
    public function testTheCompilerCanReadEverySubModuleClassWithNoGatewayInstalled(): void
    {
        $classes = [];
        $needed = [];
        foreach ($this->scannedFiles() as $relative => $path) {
            if (strpos($relative, 'SubModules/') !== 0
                && strpos($relative, 'Model/Payment/OffSession/') !== 0
                && strpos($relative, 'Observer/OffSession/') !== 0
            ) {
                continue;
            }
            $types = self::compilerVisibleTypes((string)file_get_contents($path));
            if ($types) {
                $classes[$path] = array_keys($types);
                $needed = array_merge($needed, ...array_values($types));
            }
        }
        $autoload = dirname((string)(new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $list = (string)tempnam(sys_get_temp_dir(), 'sicommon-compile-');
        $script = $list . '.php';
        $output = [];
        $status = -1;
        try {
            file_put_contents($list, json_encode([
                'autoload' => $autoload,
                'classes' => $classes,
                'gateways' => self::GATEWAY_NAMESPACES,
                'magento' => self::alwaysInstalledMagentoNamespaces(),
            ]));
            file_put_contents($script, self::CHILD);
            $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=512M ' . escapeshellarg($script) . ' ' . escapeshellarg($list) . ' 2>&1';
            exec($command, $output, $status);
        } finally {
            @unlink($list);
            @unlink($script);
        }
        $result = json_decode((string)end($output), true);
        self::assertIsArray($result, 'child PHP (status ' . $status . '): ' . implode("\n", $output));
        self::assertSame([], $result['preloaded'], 'gateway types loaded before the check could hide them');
        // a Composer "files" autoload may declare an optional module's type up front (mollie/magento2 declares
        // QuoteGraphQl's AdditionalDataProviderInterface): it hides nothing as long as no class here needs it
        self::assertSame([], array_values(array_intersect($result['preloadedOptional'], $needed)), 'optional Magento types loaded before the check');
        self::assertSame([], $result['failed']);
        self::assertSame(count($classes, COUNT_RECURSIVE) - count($classes), $result['read'], 'every class read');
        self::assertGreaterThan(40, $result['read']);
        self::assertArrayHasKey(dirname(__DIR__, 3) . '/Model/Payment/OffSession/Strategy/VaultStrategy.php', $classes);
    }

    public function testTheChecksSeeEveryWayAClassCanNeedAGatewayType(): void
    {
        $code = <<<'PHP'
<?php
namespace SalesIgniter\Common\SubModules\Example\Model;

use Adyen\Payment\Helper\Vault;
use Mollie\Payment\Service\Order\{TransactionPartInterface as Part, Other};
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface, Magento\Framework\ObjectManagerInterface;

#[\Attribute]
final class Example extends \StripeIntegration\Payments\Model\Base implements Part, \Countable
{
    use \Braintree\SomeTrait;

    public function __construct(
        private readonly Vault $vault,
        ?\PayPal\Braintree\Gateway\Config\Config $config = null,
        Local|CardRepositoryInterface $local = null,
        ObjectManagerInterface $om = null,
        string $mode = \Stripe\Stripe::VERSION,
        array ...$rest
    ) {
        $x = function () use ($vault) {
            return new \Mollie\Payment\Model\Anything();
        };
    }

    public function afterBuild(\Adyen\Payment\Gateway\Request\RecurringVaultDataBuilder $subject, string $class = Example::class): array
    {
        return [];
    }
}

interface ExampleInterface extends Other, \Traversable
{
}
PHP;
        $types = self::compilerVisibleTypes($code);
        self::assertSame(['SalesIgniter\\Common\\SubModules\\Example\\Model\\Example', 'SalesIgniter\\Common\\SubModules\\Example\\Model\\ExampleInterface'], array_keys($types));
        self::assertEqualsCanonicalizing(
            [
                'StripeIntegration\\Payments\\Model\\Base',
                'Mollie\\Payment\\Service\\Order\\TransactionPartInterface',
                'Countable',
                'Braintree\\SomeTrait',
                'Adyen\\Payment\\Helper\\Vault',
                'PayPal\\Braintree\\Gateway\\Config\\Config',
                'SalesIgniter\\Common\\SubModules\\Example\\Model\\Local',
                'ParadoxLabs\\TokenBase\\Api\\CardRepositoryInterface',
                'Magento\\Framework\\ObjectManagerInterface',
                'Stripe\\Stripe',
            ],
            $types['SalesIgniter\\Common\\SubModules\\Example\\Model\\Example'],
            'parent, interfaces, traits, constructor types and defaults; not method bodies, not other methods'
        );
        self::assertEqualsCanonicalizing(['Mollie\\Payment\\Service\\Order\\Other', 'Traversable'], $types['SalesIgniter\\Common\\SubModules\\Example\\Model\\ExampleInterface']);
        self::assertTrue(self::isGatewayType('StripeIntegration\\Payments\\Model\\ConfigFactory'), 'generated factories and proxies of gateway classes too');
        self::assertFalse(self::isGatewayType('SalesIgniter\\Common\\SubModules\\Stripe\\Model\\StripeGateway'));
    }

    /** Every sub-module is registered only behind a gateway marker class, and every marker is a gateway type. */
    public function testEverySubModuleIsRegisteredOnlyWhereItsGatewayIsInstalled(): void
    {
        $root = dirname(__DIR__, 3);
        $registration = (string)file_get_contents($root . '/registration.php');
        preg_match_all("/'(SalesIgniter_CommonPay\\w+)' => \\['(\\w+)', '([\\w\\\\\\\\]+)'\\]/", $registration, $rows, PREG_SET_ORDER);
        $registered = [];
        foreach ($rows as [, $module, $dir, $marker]) {
            $registered[] = $dir;
            self::assertTrue(self::isGatewayType(stripslashes($marker)), $module . ' marker ' . $marker . ' is not in a gateway namespace of this test');
            self::assertStringContainsString('"' . $module . '"', (string)file_get_contents($root . '/SubModules/' . $dir . '/etc/module.xml'));
        }
        $dirs = array_map('basename', glob($root . '/SubModules/*', GLOB_ONLYDIR) ?: []);
        sort($dirs);
        sort($registered);
        self::assertSame($dirs, $registered, 'every SubModules directory has a gateway marker in registration.php');
    }

    /**
     * etc/ of SalesIgniter_Common itself is read on every store: no type, preference, plugin or argument naming a
     * gateway class or a class of an optional Magento module.
     */
    public function testTheAlwaysLoadedConfigNamesNoGatewayClass(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/etc', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (substr($file->getPathname(), -4) !== '.xml') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                $where = substr($file->getPathname(), strlen($root) + 1) . ':' . ($n + 1) . ': ' . trim($line);
                foreach (self::GATEWAY_NAMESPACES as $ns) {
                    if (preg_match('/(?<![\w\\\\])' . preg_quote($ns, '/') . '\w/', $line)) {
                        $hits[] = $where;
                    }
                }
                preg_match_all('/(?<![\w\\\\])Magento\\\\\w+\\\\[\w\\\\]+/', $line, $names);
                foreach ($names[0] as $name) {
                    if (self::isOptionalCoreType($name)) {
                        $hits[] = $where;
                    }
                }
            }
        }
        self::assertSame([], $hits);
    }

    /** A Magento type from a module a store with SalesIgniter_Common may lack (see alwaysInstalledMagentoNamespaces()). */
    private static function isOptionalCoreType(string $type): bool
    {
        $type = ltrim($type, '\\');
        if (strpos($type, 'Magento\\') !== 0) {
            return false;
        }
        foreach (self::alwaysInstalledMagentoNamespaces() as $ns) {
            if (strpos($type, $ns) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * The Magento namespaces every store with SalesIgniter_Common has: magento/framework, the modules in
     * etc/module.xml's sequence, and their magento/module-* and magento/framework* Composer requirements,
     * recursively (a store cannot remove one of those without removing a module this one depends on). Read from
     * the installed packages' composer.json (their psr-4 roots). On Magento 2.4.9 that is about 60 modules;
     * Magento_InstantPurchase, Magento_Paypal and Payment Services are not among them.
     *
     * @return list<string> namespace prefixes ending in a backslash
     */
    private static function alwaysInstalledMagentoNamespaces(): array
    {
        if (self::$alwaysInstalled !== null) {
            return self::$alwaysInstalled;
        }
        preg_match_all('/<module name="Magento_(\w+)"/', (string)file_get_contents(dirname(__DIR__, 3) . '/etc/module.xml'), $modules);
        self::assertNotEmpty($modules[1], 'etc/module.xml has a Magento sequence');
        $queue = ['magento/framework'];
        foreach ($modules[1] as $module) {
            $queue[] = 'magento/module-' . strtolower((string)preg_replace('/(?<!^)[A-Z]/', '-$0', $module));
        }
        $seen = [];
        $namespaces = [];
        while ($queue) {
            $package = array_pop($queue);
            if (isset($seen[$package])) {
                continue;
            }
            $seen[$package] = true;
            $path = InstalledVersions::isInstalled($package) ? InstalledVersions::getInstallPath($package) : null;
            self::assertNotNull($path, $package . ' (a dependency of etc/module.xml) is installed');
            $json = json_decode((string)file_get_contents($path . '/composer.json'), true);
            foreach (array_keys($json['autoload']['psr-4'] ?? []) as $ns) {
                $namespaces[] = $ns;
            }
            foreach (array_keys($json['require'] ?? []) as $dependency) {
                if (strpos($dependency, 'magento/module-') === 0 || strpos($dependency, 'magento/framework') === 0) {
                    $queue[] = $dependency;
                }
            }
        }
        self::assertContains('Magento\\Vault\\', $namespaces);
        return self::$alwaysInstalled = array_values(array_unique($namespaces));
    }

    private static function isGatewayType(string $type): bool
    {
        foreach (self::GATEWAY_NAMESPACES as $ns) {
            if (strpos(ltrim($type, '\\'), $ns) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The PHP files setup:di:compile reads in this package: all of them but the top-level Test/.
     *
     * @return array<string, string> path relative to the package => absolute path
     */
    private function scannedFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (substr($relative, -4) === '.php' && strpos($relative, 'Test/') !== 0 && strpos($relative, '.') !== 0) {
                $files[$relative] = $file->getPathname();
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * What the compiler has to load for each class, interface, trait or enum declared in the code: parent(s),
     * interfaces, traits, and the constructor's parameter types and the classes its default values name.
     * Anonymous classes and method bodies are not read by the compiler and are skipped.
     *
     * @return array<string, list<string>> declared class => fully qualified names it needs
     */
    private static function compilerVisibleTypes(string $code): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($code),
            static fn(\PhpToken $t) => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG])
        ));
        $count = count($tokens);
        $namespace = '';
        $uses = [];
        $result = [];
        $depth = 0;
        $class = null;
        $classDepth = 0;
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t->is(T_ATTRIBUTE)) {
                $i = self::skipBalanced($tokens, $i, '#[', ']');
                continue;
            }
            if ($t->text === '{' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                continue;
            }
            if ($t->text === '}') {
                $depth--;
                if ($class !== null && $depth < $classDepth) {
                    $class = null;
                }
                continue;
            }
            if ($t->is(T_NAMESPACE) && $class === null && isset($tokens[$i + 1]) && $tokens[$i + 1]->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[++$i]->text;
                $uses = [];
                continue;
            }
            if ($t->is(T_USE) && $class === null && $depth <= 1 && ($tokens[$i - 1]->text ?? '') !== ')') { // not a closure's use
                $i = self::readImports($tokens, $i + 1, $uses);
                continue;
            }
            if ($t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $class === null) {
                $previous = $tokens[$i - 1] ?? null;
                if ($previous !== null && $previous->is([T_DOUBLE_COLON, T_NEW])) {
                    continue; // Foo::class, or an anonymous class (built only when that code runs)
                }
                if (!isset($tokens[$i + 1]) || !$tokens[$i + 1]->is(T_STRING)) {
                    continue;
                }
                $class = ($namespace !== '' ? $namespace . '\\' : '') . $tokens[++$i]->text;
                $result[$class] = [];
                for ($i++; $i < $count && $tokens[$i]->text !== '{'; $i++) {
                    if (self::isName($tokens[$i])) {
                        $result[$class][] = self::resolve($tokens[$i]->text, $namespace, $uses);
                    }
                }
                $depth++;
                $classDepth = $depth;
                continue;
            }
            if ($class === null || $depth !== $classDepth) {
                continue;
            }
            if ($t->is(T_USE)) { // trait
                for ($i++; $i < $count && $tokens[$i]->text !== ';' && $tokens[$i]->text !== '{'; $i++) {
                    if (self::isName($tokens[$i])) {
                        $result[$class][] = self::resolve($tokens[$i]->text, $namespace, $uses);
                    }
                }
                $i--;
                continue;
            }
            if ($t->is(T_FUNCTION) && isset($tokens[$i + 1]) && strtolower($tokens[$i + 1]->text) === '__construct') {
                $open = $i + 2;
                $close = self::skipBalanced($tokens, $open, '(', ')');
                foreach (self::constructorTypes($tokens, $open + 1, $close) as $name) {
                    $result[$class][] = self::resolve($name, $namespace, $uses);
                }
                $i = $close;
            }
        }
        foreach ($result as $name => $types) {
            $result[$name] = array_values(array_unique(array_filter(
                $types,
                static fn(string $type) => !in_array(strtolower($type), self::BUILTIN, true)
            )));
        }
        return $result;
    }

    /**
     * Names in the parameter types, and the classes the default values name (Foo::CONSTANT, new Foo()): the
     * compiler evaluates defaults (ReflectionParameter::getDefaultValue()).
     *
     * @param \PhpToken[] $tokens
     * @return list<string>
     */
    private static function constructorTypes(array $tokens, int $from, int $to): array
    {
        $names = [];
        $inDefault = false;
        $nesting = 0;
        for ($i = $from; $i < $to; $i++) {
            $t = $tokens[$i];
            if ($t->is(T_ATTRIBUTE)) {
                $i = self::skipBalanced($tokens, $i, '#[', ']');
                continue;
            }
            if (in_array($t->text, ['(', '['], true)) {
                $nesting++;
            } elseif (in_array($t->text, [')', ']'], true)) {
                $nesting--;
            } elseif ($t->text === ',' && $nesting === 0) {
                $inDefault = false;
            } elseif ($t->text === '=' && $nesting === 0) {
                $inDefault = true;
            } elseif (self::isName($t)) {
                $next = $tokens[$i + 1] ?? null;
                $previous = $tokens[$i - 1] ?? null;
                if (!$inDefault
                    || ($next !== null && $next->is(T_DOUBLE_COLON))
                    || ($previous !== null && $previous->is(T_NEW))
                ) {
                    $names[] = $t->text;
                }
            }
        }
        return $names;
    }

    /**
     * use A\B; use A\B as C, D\E; use A\{B, C as D}; (function / const imports are skipped)
     *
     * @param \PhpToken[] $tokens
     * @param array<string, string> $uses alias => fully qualified name
     */
    private static function readImports(array $tokens, int $i, array &$uses): int
    {
        if (isset($tokens[$i]) && $tokens[$i]->is([T_FUNCTION, T_CONST])) {
            while (isset($tokens[$i]) && $tokens[$i]->text !== ';') {
                $i++;
            }
            return $i;
        }
        $prefix = '';
        $name = '';
        $alias = '';
        $add = static function () use (&$prefix, &$name, &$alias, &$uses): void {
            if ($name !== '') {
                $full = ltrim($prefix . $name, '\\');
                $uses[$alias !== '' ? $alias : substr((string)strrchr('\\' . $full, '\\'), 1)] = $full;
            }
            $name = '';
            $alias = '';
        };
        for (; isset($tokens[$i]) && $tokens[$i]->text !== ';'; $i++) {
            $t = $tokens[$i];
            if ($t->is(T_NS_SEPARATOR) && ($tokens[$i + 1]->text ?? '') === '{') {
                $prefix = $name . '\\';
                $name = '';
                $i++;
            } elseif ($t->is(T_AS)) {
                $alias = $tokens[++$i]->text;
            } elseif ($t->text === ',' || $t->text === '}') {
                $add();
            } elseif (self::isName($t)) {
                $name = $t->text;
            }
        }
        $add();
        return $i;
    }

    /** @param \PhpToken[] $tokens index of the matching closing token */
    private static function skipBalanced(array $tokens, int $i, string $open, string $close): int
    {
        $level = 0;
        for ($count = count($tokens); $i < $count; $i++) {
            $text = $tokens[$i]->text;
            if ($text === $open || ($open === '#[' && $text === '[')) {
                $level++;
            } elseif ($text === $close && --$level === 0) {
                return $i;
            }
        }
        return $i;
    }

    private static function isName(\PhpToken $t): bool
    {
        return $t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE]);
    }

    /** @param array<string, string> $uses */
    private static function resolve(string $name, string $namespace, array $uses): string
    {
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        if (stripos($name, 'namespace\\') === 0) {
            return ($namespace !== '' ? $namespace . '\\' : '') . substr($name, 10);
        }
        if (in_array(strtolower($name), self::BUILTIN, true)) {
            return $name;
        }
        $first = explode('\\', $name)[0];
        if (isset($uses[$first])) {
            return $uses[$first] . substr($name, strlen($first));
        }
        return ($namespace !== '' ? $namespace . '\\' : '') . $name;
    }

    /** The child PHP: composer autoload, gateway namespaces made unloadable, then the compiler's reads. */
    private const CHILD = <<<'PHP'
<?php
$job = json_decode((string)file_get_contents($argv[1]), true);
require $job['autoload'];
$isGateway = static function (string $class) use ($job): bool {
    foreach ($job['gateways'] as $ns) {
        if (strpos(ltrim($class, '\\'), $ns) === 0) {
            return true;
        }
    }
    return false;
};
$isOptionalMagento = static function (string $class) use ($job): bool {
    $class = ltrim($class, '\\');
    if (strpos($class, 'Magento\\') !== 0) {
        return false;
    }
    foreach ($job['magento'] as $ns) {
        if (strpos($class, $ns) === 0) {
            return false;
        }
    }
    return true;
};
$declared = array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
$preloaded = array_values(array_filter($declared, $isGateway));
$preloadedOptional = array_values(array_filter($declared, $isOptionalMagento));
spl_autoload_register(static function (string $class) use ($isGateway, $isOptionalMagento): void {
    if ($isGateway($class)) {
        throw new \RuntimeException('gateway type needed: ' . $class);
    }
    if ($isOptionalMagento($class)) {
        throw new \RuntimeException('optional Magento module type needed: ' . $class);
    }
}, true, true);
$reader = new \Magento\Framework\Code\Reader\ClassReader();
$failed = [];
$read = 0;
foreach ($job['classes'] as $file => $classes) {
    foreach ($classes as $class) {
        try {
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                require_once $file;
            }
            $reader->getConstructor($class);
            $reader->getParents($class);
            $read++;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
                $message .= ' <- ' . $p->getMessage();
            }
            $failed[] = $class . ': ' . $message;
        }
    }
}
echo json_encode(['preloaded' => $preloaded, 'preloadedOptional' => $preloadedOptional, 'read' => $read, 'failed' => $failed]), "\n";
PHP;
}
