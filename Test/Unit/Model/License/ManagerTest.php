<?php
/**
 * The store's licence as rentalbookingsoftware.com's EDD Software Licensing answers for it.
 *
 * The client is a scripted fake of the EDD API, answering the way the live store does (measured
 * 2026-09-23 with the internal Pro licence): `invalid_item_id` for every download id but the key's
 * own, then that licence's status, expiry and site counts.
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
use SalesIgniter\Common\Model\License\Products;

class ManagerTest extends TestCase
{
    const KEY = 'abcdef0123456789abcdef0123456789';
    const PRO = 32867;
    const NOW = 1790121600; // 2026-09-23 00:00 UTC

    /** @var array[] every call made: [action, key, item_id, url] */
    private $calls = [];
    /** @var callable (action, itemId) => ?array */
    private $server;
    private $flag = null;
    private $now = self::NOW;
    private $configured = self::KEY;

    private function manager(): Manager
    {
        $client = $this->createMock(Client::class);
        $client->method('call')->willReturnCallback(function ($action, $key, $itemId, $url) {
            $this->calls[] = [$action, $key, $itemId, $url];
            return ($this->server)($action, $itemId, $key);
        });
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(fn () => $this->flag);
        $flags->method('saveFlag')->willReturnCallback(function ($code, $value) { $this->flag = $value; return true; });
        $flags->method('deleteFlag')->willReturnCallback(function () { $this->flag = null; return true; });
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(fn ($path) => [
            Manager::CONFIG_KEY => $this->configured === '' ? '' : 'enc:' . $this->configured,
            'web/secure/base_url' => 'https://shop.example.com/',
        ][$path] ?? null);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(fn ($v) => substr((string)$v, 4));
        $clock = $this->createMock(DateTime::class);
        $clock->method('gmtTimestamp')->willReturnCallback(fn () => $this->now);
        return new Manager($client, $flags, $config, $encryptor, $clock);
    }

    /** EDD for a Pro key: invalid_item_id elsewhere, $status on Pro. */
    private function proKey(string $status, string $expires = '2027-04-28 23:59:59', array $activate = null): void
    {
        $this->server = function ($action, $itemId) use ($status, $expires, $activate) {
            if ($action === 'activate_license') {
                return $activate ?? ['success' => true, 'license' => 'valid', 'expires' => $expires, 'site_count' => 1, 'license_limit' => 2];
            }
            if ($action === 'deactivate_license') {
                return ['success' => true, 'license' => 'deactivated'];
            }
            $base = ['success' => true, 'item_id' => $itemId, 'expires' => $expires, 'customer_name' => 'Kevin Javitz', 'license_limit' => 2, 'site_count' => 0];
            return ['license' => $itemId === self::PRO ? $status : 'invalid_item_id'] + $base;
        };
    }

    public function testSavingAKeyFindsItsProductAndActivatesIt(): void
    {
        $this->proKey('inactive');
        $status = $this->manager()->activate(self::KEY);

        $this->assertSame('valid', $status['license']);
        $this->assertSame(self::PRO, $status['item_id']);
        $this->assertSame(Products::ALL[self::PRO], $status['item_name']);
        $this->assertSame('2027-04-28 23:59:59', $status['expires']);
        $this->assertSame(1, $status['site_count']);
        $this->assertSame('6789', $status['key_tail']);
        $this->assertSame(
            [['check_license', 32864], ['check_license', self::PRO], ['activate_license', self::PRO]],
            array_map(fn ($c) => [$c[0], $c[2]], $this->calls)
        );
        $this->assertSame('https://shop.example.com/', $this->calls[2][3], 'activation is counted against the store URL');
        $this->assertArrayNotHasKey('key', $status, 'the key itself is never kept in the flag');
    }

    public function testAKeyAlreadyActiveHereIsNotActivatedAgain(): void
    {
        $this->proKey('valid');
        $this->manager()->activate(self::KEY);
        $this->assertNotContains('activate_license', array_column($this->calls, 0));
    }

    public function testAnExpiredKeyIsReportedAsExpiredWithARenewalLink(): void
    {
        $this->proKey('expired', '2026-08-01 23:59:59');
        $m = $this->manager();
        $status = $m->activate(self::KEY);
        $this->assertSame('expired', $status['license']);
        $this->assertSame(-53, $m->daysLeft($status));
        $this->assertSame(
            'https://rentalbookingsoftware.com/checkout/?edd_license_key=' . self::KEY . '&download_id=' . self::PRO,
            $m->renewalUrl($status)
        );
    }

    public function testActivationRefusedForLackOfSitesSaysSo(): void
    {
        $this->proKey('site_inactive', '2027-04-28 23:59:59', ['success' => false, 'license' => 'invalid', 'error' => 'no_activations_left', 'license_limit' => 2, 'site_count' => 2]);
        $status = $this->manager()->activate(self::KEY);
        $this->assertSame('no_activations_left', $status['license']);
        $this->assertSame(Products::ALL[self::PRO], $status['item_name']);
    }

    public function testAnUnknownKeyStopsAtTheFirstAnswer(): void
    {
        $this->server = fn () => ['success' => false, 'license' => 'invalid'];
        $status = $this->manager()->activate(self::KEY);
        $this->assertSame('invalid', $status['license']);
        $this->assertCount(1, $this->calls);
    }

    public function testAKeyForNoMagentoProductIsWrongProduct(): void
    {
        $this->server = fn ($action, $itemId) => ['success' => true, 'license' => 'invalid_item_id', 'item_id' => $itemId];
        $status = $this->manager()->activate(self::KEY);
        $this->assertSame(Manager::WRONG_PRODUCT, $status['license']);
        $this->assertCount(count(Products::ALL), $this->calls);
    }

    public function testARequestForQuoteKeyIsFoundAndActivated(): void
    {
        // a key of the Request for Quote download (39844): invalid_item_id for every rental product
        $this->server = function ($action, $itemId) {
            if ($action === 'activate_license') {
                return ['success' => true, 'license' => 'valid', 'item_id' => $itemId, 'expires' => '2027-09-28 23:59:59', 'site_count' => 1, 'license_limit' => 2];
            }
            return ['success' => true, 'item_id' => $itemId, 'license' => $itemId === 39844 ? 'site_inactive' : 'invalid_item_id'];
        };
        $status = $this->manager()->activate(self::KEY);

        $this->assertSame('valid', $status['license']);
        $this->assertSame(39844, $status['item_id']);
        $this->assertSame('Magento 2 Request for Quote & Hide Price', $status['item_name']);
        $this->assertSame('2027-09-28 23:59:59', $status['expires']);
        $this->assertSame(['activate_license', 39844], [end($this->calls)[0], end($this->calls)[2]]);
        $this->assertSame(
            'https://rentalbookingsoftware.com/checkout/?edd_license_key=' . self::KEY . '&download_id=39844',
            $this->manager()->renewalUrl($status)
        );
    }

    public function testAnUnreachableServerIsNotAnInvalidKey(): void
    {
        $this->server = fn () => null;
        $this->assertSame(Manager::UNREACHABLE, $this->manager()->activate(self::KEY)['license']);
    }

    public function testStatusIsCachedForTwelveHoursThenReAskedWithOneCall(): void
    {
        $this->proKey('valid');
        $m = $this->manager();
        $m->activate(self::KEY);
        $this->calls = [];

        $this->now += Manager::STALE_AFTER - 60;
        $this->assertSame('valid', $m->status()['license']);
        $this->assertSame([], $this->calls, 'fresh: no request');

        $this->now += 120;
        $this->assertSame('valid', $m->status()['license']);
        $this->assertSame([['check_license', self::PRO]], array_map(fn ($c) => [$c[0], $c[2]], $this->calls), 'stale: one check against the known product');
    }

    public function testCheckNowAsksEvenWhenFresh(): void
    {
        $this->proKey('valid');
        $m = $this->manager();
        $m->activate(self::KEY);
        $this->calls = [];
        $m->status(true);
        $this->assertCount(1, $this->calls);
    }

    public function testAFailedRecheckKeepsTheLastAnswerAndSaysSo(): void
    {
        $this->proKey('valid');
        $m = $this->manager();
        $m->activate(self::KEY);
        $this->server = fn () => null;
        $status = $m->status(true);
        $this->assertSame('valid', $status['license']);
        $this->assertSame('2027-04-28 23:59:59', $status['expires']);
        $this->assertSame(self::NOW, $status['unreachable_at']);
    }

    public function testAChangedKeyIsIdentifiedAfresh(): void
    {
        $this->proKey('valid');
        $m = $this->manager();
        $m->activate(self::KEY);
        $this->configured = 'ffffffffffffffffffffffffffff0000';
        $this->calls = [];
        $m->status();
        $this->assertSame(32864, $this->calls[0][2], 'a different key starts from the first product again');
    }

    public function testNoKeyNoStatusAndNoRequest(): void
    {
        $this->configured = '';
        $this->server = fn () => $this->fail('no request without a key');
        $this->assertNull($this->manager()->status());
    }

    public function testDeactivatingUsesTheKnownProductAndForgetsTheStatus(): void
    {
        $this->proKey('valid');
        $m = $this->manager();
        $m->activate(self::KEY);
        $this->calls = [];
        $m->deactivate(self::KEY);
        $this->assertSame([['deactivate_license', self::PRO]], array_map(fn ($c) => [$c[0], $c[2]], $this->calls));
        $this->assertNull($this->flag);
    }

    public function testLifetimeLicenseHasNoDaysLeft(): void
    {
        $this->assertNull($this->manager()->daysLeft(['expires' => 'lifetime']));
    }
}
