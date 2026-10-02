<?php
/**
 * Copyright © SalesIgniter. All rights reserved.
 * See https://rentalbookingsoftware.com/license.html for license details.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Payment\OffSession;

use Magento\Framework\App\ResourceConnection;

/**
 * Signed, single-use links for payment emails ("Pay now", "Confirm your payment", "Use another
 * card"), table sicommon_pay_token. Only the sha256 of the token is stored, so a database leak does
 * not leak working links. Datetimes are UTC.
 *
 * A token names its subject by type and id: `sisub_subscription` + the subscription id (with the
 * schedule row being recovered as context_id), `sirent_balance` + the balance row id. The consumer
 * checks the subject itself (owner, state) after verify().
 */
class TokenService
{
    public const TABLE = 'sicommon_pay_token';
    public const PURPOSE_PAY_NOW = 'pay_now';
    public const PURPOSE_UPDATE_PAYMENT = 'update_payment';
    public const DEFAULT_DAYS = 14;

    /** @var ResourceConnection */
    private $resource;
    /** @var Clock */
    private $clock;

    public function __construct(ResourceConnection $resource, Clock $clock)
    {
        $this->resource = $resource;
        $this->clock = $clock;
    }

    /** @return string the raw token for the link (never stored) */
    public function create(string $subjectType, int $subjectId, ?int $contextId, string $purpose, int $days = self::DEFAULT_DAYS): string
    {
        $raw = bin2hex(random_bytes(24));
        $this->resource->getConnection()->insert($this->resource->getTableName(self::TABLE), [
            'token_hash' => hash('sha256', $raw),
            'purpose' => $purpose,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context_id' => $contextId,
            'expires_at' => $this->clock->nowUtc()->modify('+' . max(1, $days) . ' days')->format('Y-m-d H:i:s'),
            'created_at' => $this->clock->nowString(),
        ]);
        return $raw;
    }

    /**
     * The token's row when it is valid (known, unused, not expired, and of $subjectType when given),
     * else null. Keys: token_hash, purpose, subject_type, subject_id, context_id, expires_at, used_at,
     * created_at.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $raw, ?string $subjectType = null): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $raw)) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(self::TABLE))
                ->where('token_hash = ?', hash('sha256', $raw))
        );
        if (!$row || $row['used_at'] !== null || (string)$row['expires_at'] < $this->clock->nowString()) {
            return null;
        }
        if ($subjectType !== null && (string)$row['subject_type'] !== $subjectType) {
            return null;
        }
        return $row;
    }

    /** Spend a token; false when it was unknown or already used (another request won). */
    public function markUsed(string $raw): bool
    {
        return $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['used_at' => $this->clock->nowString()],
            ['token_hash = ?' => hash('sha256', $raw), 'used_at IS NULL']
        ) === 1;
    }

    /** Invalidate every open link of a subject (after it was paid by another path). */
    public function expireAll(string $subjectType, int $subjectId): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['used_at' => $this->clock->nowString()],
            ['subject_type = ?' => $subjectType, 'subject_id = ?' => $subjectId, 'used_at IS NULL']
        );
    }
}
