<?php
namespace SalesIgniter\Common\Model\License;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The store's Sales Igniter licence: which product the key is for, whether it is active on this
 * site, and when it expires. Each extension's License settings group shows it (Rentals > Settings >
 * License; Request for Quote > License).
 *
 * Shared by the Sales Igniter extensions since 1.2.57 (it moved here from releaserental2, which keeps
 * a subclass under its old name). Each extension passes its own config path and flag code; the
 * defaults are the rental extension's, so an unconfigured instance behaves exactly as rental's did.
 *
 * The last answer from rentalbookingsoftware.com is kept in a flag (not config, so saving the
 * settings page never writes it back) and is re-asked when it is older than STALE_AFTER, when the
 * key changes, or when an admin clicks "Check now". A store that cannot reach
 * rentalbookingsoftware.com keeps showing the last answer, marked as not re-checked.
 *
 * The licence gates nothing in the extension. It is what updates are downloaded with (the
 * composer feeds check the same key), and this screen is where a shop sees it running out.
 */
class Manager
{
    const CONFIG_KEY = 'salesigniter_rental/license/key';
    const FLAG = 'salesigniter_rental_license';
    const STALE_AFTER = 43200; // 12 hours
    const EXPIRING_SOON_DAYS = 30;

    /** EDD statuses, as check_license / activate_license answer them */
    const VALID = 'valid';
    const EXPIRED = 'expired';
    const INACTIVE = 'inactive';
    const SITE_INACTIVE = 'site_inactive';
    const DISABLED = 'disabled';
    const INVALID = 'invalid';
    /** ours: a real key, but for none of the Magento products */
    const WRONG_PRODUCT = 'wrong_product';
    /** ours: rentalbookingsoftware.com could not be reached */
    const UNREACHABLE = 'unreachable';

    private $client;
    private $flagManager;
    private $scopeConfig;
    private $encryptor;
    private $dateTime;
    /** @var string config path of the (encrypted) key */
    private $configPath;
    /** @var string flag code the last answer is cached under */
    private $flagCode;

    public function __construct(
        Client $client,
        FlagManager $flagManager,
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        DateTime $dateTime,
        string $configPath = self::CONFIG_KEY,
        string $flagCode = self::FLAG
    ) {
        $this->configPath = $configPath;
        $this->flagCode = $flagCode;
        $this->client = $client;
        $this->flagManager = $flagManager;
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->dateTime = $dateTime;
    }

    /** The key as saved in Rentals > Settings, decrypted; '' when none. */
    public function configuredKey(): string
    {
        $stored = (string)$this->scopeConfig->getValue($this->configPath);
        return $stored === '' ? '' : trim((string)$this->encryptor->decrypt($stored));
    }

    /** The address EDD counts an activation against: the default store's secure base URL. */
    public function siteUrl(): string
    {
        return (string)$this->scopeConfig->getValue('web/secure/base_url');
    }

    /**
     * The licence status for the configured key, re-asked when stale or when $force.
     *
     * @return array|null null when no key is configured; otherwise see record()
     */
    public function status(bool $force = false): ?array
    {
        $key = $this->configuredKey();
        if ($key === '') {
            return null;
        }
        $saved = $this->flagManager->getFlagData($this->flagCode);
        $current = is_array($saved) && ($saved['key_hash'] ?? '') === $this->hash($key);
        if ($current && !$force && ($this->now() - (int)($saved['checked_at'] ?? 0)) < self::STALE_AFTER) {
            return $saved;
        }
        return $this->check($key, $current ? $saved : null);
    }

    /**
     * Activates $key on this site: finds its product, and activates unless EDD already counts
     * this site. Called when the key is saved.
     */
    public function activate(string $key): array
    {
        $found = $this->identify($key);
        if (in_array($found['license'], [self::INACTIVE, self::SITE_INACTIVE], true)) {
            $answer = $this->client->call('activate_license', $key, (int)$found['item_id'], $this->siteUrl());
            if ($answer === null) {
                return $this->save($key, ['license' => self::UNREACHABLE] + $found);
            }
            $status = !empty($answer['success']) ? self::VALID : (string)($answer['error'] ?? $answer['license'] ?? self::INVALID);
            return $this->save($key, ['license' => $status, 'error' => $answer['error'] ?? ''] + $this->fields($answer) + $found);
        }
        return $this->save($key, $found);
    }

    /** Frees this site's activation of $key. Best effort: a key being replaced must not block the save. */
    public function deactivate(string $key): void
    {
        $saved = $this->flagManager->getFlagData($this->flagCode);
        $itemId = is_array($saved) && ($saved['key_hash'] ?? '') === $this->hash($key) ? (int)($saved['item_id'] ?? 0) : 0;
        if (!$itemId) {
            $itemId = (int)($this->identify($key)['item_id'] ?? 0);
        }
        if ($itemId) {
            $this->client->call('deactivate_license', $key, $itemId, $this->siteUrl());
        }
        $this->flagManager->deleteFlag($this->flagCode);
    }

    /** The EDD renewal link for the configured key, or '' when its product is not known. */
    public function renewalUrl(?array $status = null): string
    {
        $status = $status ?? $this->status();
        $key = $this->configuredKey();
        return $key !== '' && !empty($status['item_id']) ? Products::renewalUrl($key, (int)$status['item_id']) : '';
    }

    /** Whole days until expiry (negative once past); null for a lifetime licence or no date. */
    public function daysLeft(array $status): ?int
    {
        $expires = $status['expires'] ?? '';
        if ($expires === '' || $expires === 'lifetime') {
            return null;
        }
        $ts = strtotime($expires . ' UTC');
        return $ts === false ? null : (int)floor(($ts - $this->now()) / 86400);
    }

    /**
     * Which Magento product $key belongs to, and its status there. Asks each product in turn;
     * EDD answers `invalid_item_id` for all but the key's own.
     */
    private function identify(string $key): array
    {
        foreach (array_keys(Products::ALL) as $itemId) {
            $answer = $this->client->call('check_license', $key, $itemId, $this->siteUrl());
            if ($answer === null) {
                return ['license' => self::UNREACHABLE];
            }
            $status = (string)($answer['license'] ?? self::INVALID);
            if ($status === self::INVALID || empty($answer['success'])) {
                return ['license' => self::INVALID];
            }
            if ($status !== 'invalid_item_id') {
                return ['license' => $status, 'item_id' => $itemId, 'item_name' => Products::ALL[$itemId]] + $this->fields($answer);
            }
        }
        return ['license' => self::WRONG_PRODUCT];
    }

    /** Re-asks for a key whose product is known, else identifies it from scratch. */
    private function check(string $key, ?array $known): array
    {
        if ($known && !empty($known['item_id'])) {
            $answer = $this->client->call('check_license', $key, (int)$known['item_id'], $this->siteUrl());
            if ($answer === null) {
                // keep what we knew; say it could not be re-checked
                $known['unreachable_at'] = $this->now();
                $known['checked_at'] = $this->now();
                $this->flagManager->saveFlag($this->flagCode, $known);
                return $known;
            }
            $status = (string)($answer['license'] ?? self::INVALID);
            if ($status !== 'invalid_item_id') {
                return $this->save($key, ['license' => $status, 'item_id' => (int)$known['item_id'], 'item_name' => $known['item_name'] ?? ''] + $this->fields($answer));
            }
        }
        return $this->save($key, $this->identify($key));
    }

    private function fields(array $answer): array
    {
        $out = [];
        foreach (['expires', 'customer_name', 'license_limit', 'site_count'] as $field) {
            if (isset($answer[$field])) {
                $out[$field] = $answer[$field];
            }
        }
        return $out;
    }

    /**
     * @return array license (EDD status or ours), item_id, item_name, expires ('Y-m-d H:i:s' |
     *               'lifetime'), customer_name, license_limit, site_count, key_tail, checked_at, error
     */
    private function save(string $key, array $data): array
    {
        $record = $data + ['error' => ''];
        $record['key_hash'] = $this->hash($key);
        $record['key_tail'] = substr($key, -4);
        $record['checked_at'] = $this->now();
        unset($record['unreachable_at']);
        $this->flagManager->saveFlag($this->flagCode, $record);
        return $record;
    }

    private function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    private function now(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }
}
