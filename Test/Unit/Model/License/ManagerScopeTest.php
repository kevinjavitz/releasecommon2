<?php
/**
 * One Manager class serves several extensions (1.2.57): each reads its own config path and caches
 * its answer under its own flag, and the defaults are the rental extension's.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\License;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\License\Client;
use SalesIgniter\Common\Model\License\Manager;

class ManagerScopeTest extends TestCase
{
    /** @var string[] flag codes touched */
    private array $flags = [];

    private function manager(array $config, ?string $path = null, ?string $flag = null): Manager
    {
        $client = $this->createMock(Client::class);
        $client->method('call')->willReturn(['success' => true, 'license' => 'valid', 'expires' => 'lifetime']);
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(function ($code) {
            $this->flags[] = 'get:' . $code;
            return null;
        });
        $flags->method('saveFlag')->willReturnCallback(function ($code) {
            $this->flags[] = 'save:' . $code;
            return true;
        });
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($p) => $config[$p] ?? null);
        $enc = $this->createMock(EncryptorInterface::class);
        $enc->method('decrypt')->willReturnCallback(fn ($v) => (string)$v);
        $clock = $this->createMock(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(1790121600);
        $args = [$client, $flags, $scope, $enc, $clock];
        if ($path !== null) {
            $args[] = $path;
            $args[] = $flag;
        }
        return new Manager(...$args);
    }

    public function testTheDefaultsAreTheRentalExtensionsPathAndFlag(): void
    {
        $manager = $this->manager([Manager::CONFIG_KEY => 'rental-key']);
        $this->assertSame('rental-key', $manager->configuredKey());
        $manager->status();
        $this->assertContains('save:' . Manager::FLAG, $this->flags);
        $this->assertSame('salesigniter_rental/license/key', Manager::CONFIG_KEY);
        $this->assertSame('salesigniter_rental_license', Manager::FLAG);
    }

    public function testAnotherExtensionReadsItsOwnKeyAndCachesUnderItsOwnFlag(): void
    {
        $manager = $this->manager(
            [Manager::CONFIG_KEY => 'rental-key', 'salesigniter_rfq/license/key' => 'rfq-key'],
            'salesigniter_rfq/license/key',
            'salesigniter_rfq_license'
        );
        $this->assertSame('rfq-key', $manager->configuredKey());
        $manager->status();
        $this->assertContains('save:salesigniter_rfq_license', $this->flags);
        $this->assertNotContains('save:' . Manager::FLAG, $this->flags);
        $this->assertNotContains('get:' . Manager::FLAG, $this->flags);
    }
}
