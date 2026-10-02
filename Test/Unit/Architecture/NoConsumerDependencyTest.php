<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The off-session payment layer is shared: subscriptions charge renewals with it and the rental
 * extension charges booking balances with it. It must never name either consumer, or a store with
 * only one of them fails setup:di:compile (and the layer starts to grow one consumer's rules).
 * Consumer rules go in the consumer, through the extension points (ChargeSubjectInterface,
 * SubjectSaverInterface, SavedCardRequirementInterface, MitContext context values).
 */
class NoConsumerDependencyTest extends TestCase
{
    private const PATHS = [
        'Model/Payment/OffSession',
        'Observer/OffSession',
        'SubModules',
        'etc/events.xml',
        'etc/frontend/events.xml',
        'etc/di.xml',
        'etc/db_schema.xml',
        'registration.php',
    ];

    public function testTheOffSessionLayerNeverNamesRentalOrSubscriptions(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = [];
        foreach (self::PATHS as $relative) {
            foreach ($this->files($root . '/' . $relative) as $path) {
                foreach (file($path) ?: [] as $n => $line) {
                    if (preg_match('/SalesIgniter(\\\\{1,2}|_)(Rental|Subscriptions)/', $line)) {
                        $hits[] = substr($path, strlen($root) + 1) . ':' . ($n + 1) . ': ' . trim($line);
                    }
                }
            }
        }
        self::assertSame([], $hits);
    }

    public function testComposerRequiresNeitherConsumer(): void
    {
        $composer = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);
        self::assertArrayNotHasKey('salesigniter/releaserental2', $composer['require'] ?? []);
        self::assertArrayNotHasKey('salesigniter/releasesubscriptions2', $composer['require'] ?? []);
    }

    /** @return string[] */
    private function files(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        if (!is_dir($path)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $p = $file->getPathname();
            if (strpos($p, '/Test/') === false && preg_match('/\.(php|xml|phtml|js|graphqls|json)$/', $p)) {
                $out[] = $p;
            }
        }
        sort($out);
        return $out;
    }
}
