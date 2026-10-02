<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\Clock;
use SalesIgniter\Common\Model\Payment\OffSession\TokenService;

/**
 * Signed payment links: only the sha256 is stored; unknown, used, expired and other-subject tokens
 * are refused. The table itself is exercised by the consumers' integration tests.
 */
class TokenServiceTest extends TestCase
{
    /** @var array<int, array> */
    private $inserted = [];
    /** @var array|false */
    private $row = false;
    /** @var array<int, array> */
    private $updates = [];

    private function service(): TokenService
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('insert')->willReturnCallback(function ($table, $data) {
            $this->inserted[] = [$table, $data];
            return 1;
        });
        $connection->method('fetchRow')->willReturnCallback(fn() => $this->row);
        $connection->method('update')->willReturnCallback(function ($table, $data, $where) {
            $this->updates[] = [$table, $data, $where];
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $clock = $this->createStub(Clock::class);
        $clock->method('nowUtc')->willReturn(new \DateTimeImmutable('2026-10-02 10:00:00', new \DateTimeZone('UTC')));
        $clock->method('nowString')->willReturn('2026-10-02 10:00:00');
        return new TokenService($resource, $clock);
    }

    public function testCreateStoresOnlyTheHashWithTheSubjectAndAUtcExpiry(): void
    {
        $raw = $this->service()->create('sirent_balance', 45, null, TokenService::PURPOSE_PAY_NOW, 3);
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $raw);
        [$table, $data] = $this->inserted[0];
        self::assertSame('sicommon_pay_token', $table);
        self::assertSame(hash('sha256', $raw), $data['token_hash']);
        self::assertNotContains($raw, $data, 'the raw token is never stored');
        self::assertSame(['sirent_balance', 45, null, 'pay_now'], [$data['subject_type'], $data['subject_id'], $data['context_id'], $data['purpose']]);
        self::assertSame('2026-10-05 10:00:00', $data['expires_at']);
    }

    public function testVerifyAcceptsOnlyAValidUnusedUnexpiredTokenOfTheRightSubject(): void
    {
        $service = $this->service();
        $raw = str_repeat('ab', 24);
        self::assertNull($service->verify('not-a-token'));
        $this->row = false;
        self::assertNull($service->verify($raw), 'unknown');
        $valid = ['token_hash' => hash('sha256', $raw), 'subject_type' => 'sisub_subscription', 'subject_id' => '7', 'context_id' => '12', 'used_at' => null, 'expires_at' => '2026-10-16 10:00:00'];
        $this->row = $valid;
        self::assertSame($valid, $service->verify($raw));
        self::assertSame($valid, $service->verify($raw, 'sisub_subscription'));
        self::assertNull($service->verify($raw, 'sirent_balance'), 'a subscription link cannot pay a balance');
        $this->row = ['used_at' => '2026-10-02 09:00:00'] + $valid;
        self::assertNull($service->verify($raw), 'single use');
        $this->row = ['expires_at' => '2026-10-02 09:59:59'] + $valid;
        self::assertNull($service->verify($raw), 'expired');
    }

    public function testMarkUsedAndExpireAllOnlyTouchOpenTokens(): void
    {
        $service = $this->service();
        $raw = str_repeat('cd', 24);
        self::assertTrue($service->markUsed($raw));
        $service->expireAll('sirent_balance', 45);
        self::assertSame(['token_hash = ?' => hash('sha256', $raw), 'used_at IS NULL'], $this->updates[0][2]);
        self::assertSame(['subject_type = ?' => 'sirent_balance', 'subject_id = ?' => 45, 'used_at IS NULL'], $this->updates[1][2]);
        self::assertSame(['used_at' => '2026-10-02 10:00:00'], $this->updates[1][1]);
    }
}
