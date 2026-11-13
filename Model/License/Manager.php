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
 *
 * Live and staging site (1.2.61): a licence sold as 1 site covers 1 live site and 1 staging site.
 * rentalbookingsoftware.com adds `si_site_role` (this address: live, staging or local) and
 * `si_sites` (counts and what the licence covers) to its check and activation answers, and
 * `si_message` when it has words for the shop (a full licence). They are kept with the rest of the
 * answer; an answer without them (a store before that change) leaves none. activate() can ask for
 * the staging spot, markSite() moves this site between live and staging later.
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

    /** this site's role on its licence (rentalbookingsoftware.com's labels) */
    const ROLE_LIVE = 'live';
    const ROLE_STAGING = 'staging';
    const ROLE_LOCAL = 'local';

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
     *
     * @param string $role 'staging' or 'live' when the admin said ("This is a staging site" on the
     *                     License panel); '' asks for the staging spot only when the key's live spot
     *                     is used by another address and the licence covers a staging site
     */
    public function activate(string $key, string $role = ''): array
    {
        $found = $this->identify($key);
        if (in_array($found['license'], [self::INACTIVE, self::SITE_INACTIVE], true)) {
            if (!in_array($role, [self::ROLE_LIVE, self::ROLE_STAGING], true)) {
                $role = $this->liveSpotTaken($found) ? self::ROLE_STAGING : '';
            }
            $answer = $this->client->call('activate_license', $key, (int)$found['item_id'], $this->siteUrl(),
                $role !== '' ? ['si_site_role' => $role] : []);
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

    /**
     * Makes this site the live or the staging site of the configured key (the store's signed
     * `si_site_role` action, the same request the WooCommerce plugins send).
     *
     * @return array result: 'live' | 'staging' (done; what the store now says) | 'no_key' |
     *               'inactive' (not active here) | 'busy' (too many changes, try later) |
     *               'not_supported' (the licence has no staging site) | 'unreachable' | 'failed';
     *               status: the kept record afterwards (null without a key)
     */
    public function markSite(string $role): array
    {
        if (!in_array($role, [self::ROLE_LIVE, self::ROLE_STAGING], true)) {
            return ['result' => 'failed', 'status' => null];
        }
        $key = $this->configuredKey();
        if ($key === '') {
            return ['result' => 'no_key', 'status' => null];
        }
        $status = $this->status();
        if (!is_array($status) || ($status['license'] ?? '') !== self::VALID || empty($status['item_id'])) {
            return ['result' => 'inactive', 'status' => $status];
        }
        $itemId = (int)$status['item_id'];
        $url = $this->siteUrl();
        $ts = (string)$this->now();
        // rentalbookingsoftware.com checks "si_site_role:<role>\n item \n url \n si_mark \n si_versions \n ts"
        // against an HMAC with the key; a Magento store sends no release mark or versions
        $sig = hash_hmac('sha256', implode("\n", ['si_site_role:' . $role, (string)$itemId, $url, '', '', $ts]), $key);
        $answer = $this->client->call('si_site_role', $key, $itemId, $url, [
            'role' => $role,
            'si_mark' => '',
            'si_versions' => '',
            'si_ts' => $ts,
            'si_sig' => $sig,
        ]);
        if ($answer === null) {
            return ['result' => self::UNREACHABLE, 'status' => $status];
        }
        if (empty($answer['success'])) {
            $error = is_scalar($answer['error'] ?? null) ? (string)$answer['error'] : '';
            $map = ['rate_limited' => 'busy', 'site_inactive' => 'inactive', 'not_supported' => 'not_supported'];
            return ['result' => $map[$error] ?? 'failed', 'status' => $status];
        }
        $saved = $this->save($key, $this->fields($answer) + $status);
        $now = (string)($saved['si_site_role'] ?? '');
        return ['result' => in_array($now, [self::ROLE_LIVE, self::ROLE_STAGING], true) ? $now : $role, 'status' => $saved];
    }

    /**
     * This site's label from a kept answer: role (live | staging | local), covers_live,
     * covers_staging; null when the store said nothing about this site.
     */
    public function siteLabel(?array $status): ?array
    {
        if (!is_array($status) || !isset($status['si_sites']) || !is_array($status['si_sites'])
            || !in_array($status['si_site_role'] ?? '', [self::ROLE_LIVE, self::ROLE_STAGING, self::ROLE_LOCAL], true)) {
            return null;
        }
        return [
            'role' => (string)$status['si_site_role'],
            'covers_live' => max(1, (int)($status['si_sites']['covers_live'] ?? 1)),
            'covers_staging' => (int)($status['si_sites']['covers_staging'] ?? 0),
        ];
    }

    /**
     * True when the kept answer says the licence covers a staging site, its live spot is used by
     * another address and this one is not active on it: "This is a staging site" starts ticked.
     */
    public function liveSpotTaken(?array $status): bool
    {
        if (!is_array($status) || !isset($status['si_sites']) || !is_array($status['si_sites'])) {
            return false;
        }
        $sites = $status['si_sites'];
        return (int)($sites['covers_staging'] ?? 0) > 0
            && (int)($sites['live'] ?? 0) >= max(1, (int)($sites['covers_live'] ?? 1))
            && ($status['si_site_role'] ?? '') === '';
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
        if (isset($answer['si_sites']) && is_array($answer['si_sites'])) {
            $sites = $answer['si_sites'];
            $count = function (string $k) use ($sites): int {
                return is_scalar($sites[$k] ?? null) ? max(0, (int)$sites[$k]) : 0;
            };
            $out['si_sites'] = [
                'live' => $count('live'),
                'staging' => $count('staging'),
                'local' => $count('local'),
                'covers_live' => max(1, $count('covers_live')),
                'covers_staging' => $count('covers_staging'),
            ];
            $role = is_scalar($answer['si_site_role'] ?? null) ? (string)$answer['si_site_role'] : '';
            $out['si_site_role'] = in_array($role, [self::ROLE_LIVE, self::ROLE_STAGING, self::ROLE_LOCAL], true) ? $role : '';
            $message = is_scalar($answer['si_message'] ?? null) ? trim(strip_tags((string)$answer['si_message'])) : '';
            $out['si_message'] = mb_substr($message, 0, 500);
        }
        return $out;
    }

    /**
     * @return array license (EDD status or ours), item_id, item_name, expires ('Y-m-d H:i:s' |
     *               'lifetime'), customer_name, license_limit, site_count, key_tail, checked_at, error;
     *               with a store that labels sites also si_site_role, si_sites, si_message
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
