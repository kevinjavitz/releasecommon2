<?php
/**
 * Live and staging site (1.2.61): what rentalbookingsoftware.com says about this store's place on its licence
 * (si_site_role, si_sites, si_message), the staging spot asked for on activation, and markSite(), the store's
 * signed si_site_role action. The client is a scripted fake answering the way the store's si-site-roles does.
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

class ManagerSiteRoleTest extends TestCase
{
    const KEY = 'abcdef0123456789abcdef0123456789';
    const PRO = 32867;
    const NOW = 1790121600;
    const URL = 'https://shop.example.com/';

    /** @var array[] every call: [action, key, item_id, url, extra] */
    private $calls = [];
    /** @var callable (action, itemId, extra) => ?array */
    private $server;
    private $flag = null;
    private $configured = self::KEY;

    private function manager(string $configPath = Manager::CONFIG_KEY): Manager
    {
        $client = $this->createMock(Client::class);
        $client->method('call')->willReturnCallback(function ($action, $key, $itemId, $url, $extra = []) {
            $this->calls[] = [$action, $key, $itemId, $url, $extra];
            return ($this->server)($action, $itemId, $extra);
        });
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $value) { $this->flag = $value; return true; });
        $flags->method('deleteFlag')->willReturnCallback(function () { $this->flag = null; return true; });
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(fn ($path) => [
            $configPath => $this->configured === '' ? '' : 'enc:' . $this->configured,
            'web/secure/base_url' => self::URL,
        ][$path] ?? null);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(fn ($v) => substr((string)$v, 4));
        $clock = $this->createMock(DateTime::class);
        $clock->method('gmtTimestamp')->willReturn(self::NOW);
        return new Manager($client, $flags, $config, $encryptor, $clock, $configPath);
    }

    private static function sites(int $live, int $staging, int $coversStaging = 1): array
    {
        return ['live' => $live, 'staging' => $staging, 'local' => 0, 'covers_live' => 1, 'covers_staging' => $coversStaging];
    }

    /** A Pro key; $check is merged into every check_license answer for Pro, $activate is the activation answer. */
    private function proKey(string $status, array $check = [], ?array $activate = null): void
    {
        $this->server = function ($action, $itemId) use ($status, $check, $activate) {
            if ($action === 'activate_license') {
                return $activate ?? ['success' => true, 'license' => 'valid', 'expires' => '2027-04-28 23:59:59', 'site_count' => 1, 'license_limit' => 2];
            }
            $base = ['success' => true, 'item_id' => $itemId, 'expires' => '2027-04-28 23:59:59', 'license_limit' => 2, 'site_count' => 0];
            return $itemId === self::PRO ? ['license' => $status] + $check + $base : ['license' => 'invalid_item_id'] + $base;
        };
    }

    private function activation(): array
    {
        $found = array_values(array_filter($this->calls, fn ($c) => $c[0] === 'activate_license'));
        $this->assertCount(1, $found, 'one activation');
        return $found[0][4];
    }

    public function testTheStoresLabelForThisSiteIsKept(): void
    {
        $this->proKey('inactive', [], ['success' => true, 'license' => 'valid', 'expires' => '2027-04-28 23:59:59',
            'si_site_role' => 'live', 'si_sites' => self::sites(1, 0) + ['extra' => 'x'], 'si_sites_url' => 'https://x']);
        $m = $this->manager();
        $status = $m->activate(self::KEY);

        $this->assertSame('live', $status['si_site_role']);
        $this->assertSame(self::sites(1, 0), $status['si_sites'], 'only the five counts, as ints');
        $this->assertSame('', $status['si_message']);
        $this->assertSame(['role' => 'live', 'covers_live' => 1, 'covers_staging' => 1], $m->siteLabel($status));
    }

    public function testAStoreWithoutLabelsLeavesNoLabelAndGetsNoRole(): void
    {
        $this->proKey('inactive');
        $m = $this->manager();
        $status = $m->activate(self::KEY);

        $this->assertArrayNotHasKey('si_sites', $status);
        $this->assertNull($m->siteLabel($status));
        $this->assertFalse($m->liveSpotTaken($status));
        $this->assertSame([], $this->activation(), 'nothing extra is sent');
    }

    public function testTheRoleTheAdminTickedIsSent(): void
    {
        $this->proKey('inactive');
        $this->manager()->activate(self::KEY, Manager::ROLE_STAGING);
        $this->assertSame(['si_site_role' => 'staging'], $this->activation());

        $this->calls = [];
        $this->manager()->activate(self::KEY, Manager::ROLE_LIVE);
        $this->assertSame(['si_site_role' => 'live'], $this->activation());
    }

    public function testAKeyWhoseLiveSpotIsUsedElsewhereAsksForTheStagingSpot(): void
    {
        $this->proKey('site_inactive', ['si_site_role' => '', 'si_sites' => self::sites(1, 0)]);
        $this->manager()->activate(self::KEY);
        $this->assertSame(['si_site_role' => 'staging'], $this->activation());
    }

    public function testNoStagingSpotIsAskedForWhenTheLicenceHasNone(): void
    {
        $this->proKey('site_inactive', ['si_site_role' => '', 'si_sites' => self::sites(1, 0, 0)]);
        $this->manager()->activate(self::KEY);
        $this->assertSame([], $this->activation());
    }

    public function testAFullKeyKeepsTheStoresWordsAsPlainText(): void
    {
        $words = 'This key is in use on <b>shop.example.org</b> (live) and staging.example.org (staging). '
            . str_repeat('Free one from My Account. ', 40);
        $this->proKey('site_inactive', [], ['success' => false, 'license' => 'invalid', 'error' => 'no_activations_left',
            'si_site_role' => '', 'si_sites' => self::sites(1, 1), 'si_message' => $words]);
        $status = $this->manager()->activate(self::KEY);

        $this->assertSame('no_activations_left', $status['license']);
        $this->assertStringStartsWith('This key is in use on shop.example.org (live)', $status['si_message']);
        $this->assertStringNotContainsString('<', $status['si_message']);
        $this->assertSame(500, mb_strlen($status['si_message']));
    }

    public function testMarkSiteSignsTheRequestAndKeepsTheNewLabel(): void
    {
        $this->proKey('valid', ['si_site_role' => 'live', 'si_sites' => self::sites(1, 0)]);
        $m = $this->manager();
        $m->status();
        $this->server = function ($action, $itemId, $extra) {
            return ['success' => true, 'license' => 'valid', 'si_site_role' => $extra['role'], 'si_sites' => self::sites(0, 1)];
        };
        $done = $m->markSite(Manager::ROLE_STAGING);

        $this->assertSame('staging', $done['result']);
        $this->assertSame('staging', $done['status']['si_site_role']);
        $this->assertSame('staging', $m->status()['si_site_role'], 'kept');
        [$action, $key, $itemId, $url, $extra] = end($this->calls);
        $this->assertSame(['si_site_role', self::KEY, self::PRO, self::URL], [$action, $key, $itemId, $url]);
        $this->assertSame((string)self::NOW, $extra['si_ts']);
        $this->assertSame('', $extra['si_mark']);
        $this->assertSame('', $extra['si_versions']);
        // what rentalbookingsoftware.com's si_site_roles_remote_answer() checks
        $expect = hash_hmac('sha256', implode("\n", ['si_site_role:staging', (string)self::PRO, self::URL, '', '', (string)self::NOW]), self::KEY);
        $this->assertSame($expect, $extra['si_sig']);
        $this->assertSame('staging', $extra['role']);
    }

    public function testMarkSiteSaysWhyTheStoreRefused(): void
    {
        $this->proKey('valid', ['si_site_role' => 'live', 'si_sites' => self::sites(1, 0)]);
        $m = $this->manager();
        $m->status();
        $answers = ['rate_limited' => 'busy', 'site_inactive' => 'inactive', 'not_supported' => 'not_supported',
            'bad_signature' => 'failed', 'missing' => 'failed'];
        foreach ($answers as $error => $result) {
            $this->server = fn () => ['success' => false, 'error' => $error];
            $this->assertSame($result, $m->markSite(Manager::ROLE_STAGING)['result'], $error);
        }
        $this->server = fn () => null;
        $this->assertSame(Manager::UNREACHABLE, $m->markSite(Manager::ROLE_STAGING)['result']);
        $this->assertSame('live', $m->status()['si_site_role'], 'a refusal changes nothing kept');
    }

    public function testMarkSiteNeedsAnActiveKeyAndARealRole(): void
    {
        $this->proKey('valid');
        $this->assertSame('failed', $this->manager()->markSite('local')['result']);
        $this->proKey('expired');
        $this->assertSame('inactive', $this->manager()->markSite(Manager::ROLE_STAGING)['result']);
        $this->assertNotContains('si_site_role', array_column($this->calls, 0));
        $this->configured = '';
        $this->assertSame('no_key', $this->manager()->markSite(Manager::ROLE_LIVE)['result']);
    }

    public function testSectionIsTheFirstPartOfTheKeyPath(): void
    {
        $this->assertSame('salesigniter_rental', $this->manager()->section());
        $this->assertSame('salesigniter_rfq', $this->manager('salesigniter_rfq/license/key')->section());
    }
}
