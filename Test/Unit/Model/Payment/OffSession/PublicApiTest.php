<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession as O;

/**
 * Pins the shared off-session API that subscriptions (and the rental deposits) build on. Changing a
 * signature or a constant here breaks a released consumer: add a method instead, or bump the
 * consumers' `salesigniter/releasecommon2` requirement in the same release (see
 * .claude/rules/offsession.md). Interfaces are pinned exactly (a new method breaks implementors);
 * classes may gain methods.
 */
class PublicApiTest extends TestCase
{
    private const SUBJECT = 'SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface $subject';

    public static function apis(): array
    {
        $s = self::SUBJECT;
        return [
            'ChargeSubjectInterface' => [O\ChargeSubjectInterface::class, true, [
                'getOffSessionReference(): string',
                'getOffSessionMetadata(): array',
                'getOffSessionDescription(): string',
                'getCustomerId(): ?int',
                'getStoreId(): int',
                'getPaymentMethod(): string',
                'getVaultTokenId(): ?int',
                'getPaymentData(): array',
                'setPaymentMethod(string $method)',
                'setVaultTokenId(?int $tokenId)',
                'setPaymentData(array $data)',
            ]],
            'StrategyInterface' => [O\StrategyInterface::class, true, [
                'getCode(): string',
                "captureFromOrder($s, Magento\\Sales\\Api\\Data\\OrderInterface \$order): void",
                "readiness($s, Magento\\Quote\\Model\\Quote \$quote): ?array",
                "configureQuote($s, Magento\\Quote\\Model\\Quote \$quote): void",
                "isAsync($s): bool",
                "isManual($s): bool",
                "afterOrderPlaced($s, Magento\\Sales\\Api\\Data\\OrderInterface \$order): void",
            ]],
            'DeclineMapperInterface' => [O\DeclineMapperInterface::class, true, [
                'map(array $gatewayResult, Throwable $error): ?SalesIgniter\Common\Model\Payment\OffSession\Decline',
            ]],
            'SavedCardRequirementInterface' => [O\SavedCardRequirementInterface::class, true, [
                'requiresSavedCard(Magento\Quote\Api\Data\CartInterface $quote): bool',
            ]],
            'SubjectSaverInterface' => [O\SubjectSaverInterface::class, true, [
                "supports($s): bool",
                "save($s): void",
            ]],
            'StrategyPool' => [O\StrategyPool::class, false, [
                'has(string $code): bool',
                'get(string $code): SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface',
                'forCheckoutMethod(string $methodCode, ?int $storeId = null): ?SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface',
                "forSubject($s): ?SalesIgniter\\Common\\Model\\Payment\\OffSession\\StrategyInterface",
            ]],
            'OffSessionMethods' => [O\OffSessionMethods::class, false, [
                'vaultCodesByProvider(?int $storeId = null): array',
                'vaultCodeFor(string $checkoutCode, ?int $storeId = null): ?string',
                'isVaultCode(string $code, ?int $storeId = null): bool',
                'isOffline(string $code): bool',
                'strategyFor(string $checkoutCode, ?int $storeId = null): ?string',
                'canCharge(string $checkoutCode, ?int $storeId = null): bool',
                'checkoutMethods(?int $storeId = null): array',
            ]],
            'MitContext' => [O\MitContext::class, false, [
                "run($s, bool \$variableAmount, callable \$work, array \$context = [])",
                'isActive(): bool',
                'subject(): ?SalesIgniter\Common\Model\Payment\OffSession\ChargeSubjectInterface',
                'isVariableAmount(): bool',
                'context(?string $key = null, $default = null)',
                'suppressesOrderEmail(): bool',
                'stashGatewayResult(array $result): void',
                'gatewayResult(): array',
                'clearGatewayResult(): void',
            ]],
            'TokenService' => [O\TokenService::class, false, [
                'create(string $subjectType, int $subjectId, ?int $contextId, string $purpose, int $days = 14): string',
                'verify(string $raw, ?string $subjectType = null): ?array',
                'markUsed(string $raw): bool',
                'expireAll(string $subjectType, int $subjectId): void',
            ]],
            'CartHandOff' => [O\CartHandOff::class, false, [
                'park(Magento\Quote\Model\Quote $handOff): ?int',
                'activate(Magento\Quote\Model\Quote $handOff): void',
                'start(Magento\Quote\Model\Quote $handOff): Magento\Quote\Model\Quote',
                'restore(Magento\Quote\Api\Data\CartInterface $placed): ?int',
                'reactivate(int $quoteId): ?int',
                'parkedCartId(int $handOffQuoteId): ?int',
                'restoreToSession(int $handOffQuoteId): void',
            ]],
            'Decline' => [O\Decline::class, false, [
                '__construct(string $class, string $code, string $message, bool $revoked = false)',
                'getClass(): string',
                'getCode(): string',
                'getMessage(): string',
                'isRevoked(): bool',
                'asScheduleCode(): string',
            ]],
            'DeclineClassifier' => [O\DeclineClassifier::class, false, [
                'classify(Throwable $error): SalesIgniter\Common\Model\Payment\OffSession\Decline',
            ]],
            'PaymentDeclinedException' => [O\PaymentDeclinedException::class, false, [
                '__construct(Magento\Framework\Phrase $phrase, SalesIgniter\Common\Model\Payment\OffSession\Decline $decline, ?Exception $cause = null)',
                'getDecline(): SalesIgniter\Common\Model\Payment\OffSession\Decline',
            ]],
            'PaymentLabel' => [O\PaymentLabel::class, false, [
                "forSubject($s): string",
                'forToken(Magento\Vault\Api\Data\PaymentTokenInterface $token): string',
                'methodTitle(string $code): string',
                'expiresBefore(Magento\Vault\Api\Data\PaymentTokenInterface $token, string $utc): bool',
            ]],
            'Clock' => [O\Clock::class, false, [
                'nowUtc(): DateTimeImmutable',
                'nowString(): string',
                'freeze(?string $utc): void',
            ]],
            'SavedCardRequirement' => [O\SavedCardRequirement::class, false, [
                'requiresSavedCard(Magento\Quote\Api\Data\CartInterface $quote): bool',
            ]],
            'SubjectSaver' => [O\SubjectSaver::class, false, [
                "supports($s): bool",
                "save($s): void",
            ]],
        ];
    }

    #[DataProvider('apis')]
    public function testSignaturesArePinned(string $class, bool $exact, array $expected): void
    {
        $actual = self::signatures($class);
        if ($exact) {
            self::assertEqualsCanonicalizing($expected, $actual, $class . ' changed: every implementor breaks');
            return;
        }
        self::assertSame([], array_values(array_diff($expected, $actual)), $class . ' lost or changed a public method; now: ' . implode("\n", $actual));
    }

    public function testConstantsArePinned(): void
    {
        self::assertSame('sicommon_off_session', O\StrategyInterface::PAYMENT_FLAG);
        self::assertSame('suppress_order_email', O\MitContext::SUPPRESS_ORDER_EMAIL);
        self::assertSame(['sicommon_pay_token', 'pay_now', 'update_payment', 14], [O\TokenService::TABLE, O\TokenService::PURPOSE_PAY_NOW, O\TokenService::PURPOSE_UPDATE_PAYMENT, O\TokenService::DEFAULT_DAYS]);
        self::assertSame('sicommon_restore_quote_id', O\CartHandOff::RESTORE_FIELD);
        self::assertSame(['hard', 'soft', 'action_required', 'config'], [O\Decline::HARD, O\Decline::SOFT, O\Decline::ACTION_REQUIRED, O\Decline::CONFIG]);
        self::assertSame(['vault', 'offline', 'free'], [O\OffSessionMethods::STRATEGY_VAULT, O\OffSessionMethods::STRATEGY_OFFLINE, O\OffSessionMethods::STRATEGY_FREE]);
        self::assertSame(['vault', 'offline', 'free'], [O\Strategy\VaultStrategy::CODE, O\Strategy\OfflineStrategy::CODE, O\Strategy\FreeStrategy::CODE]);
    }

    /** @return string[] "name(Type $param = default): Return" for each public method the class declares */
    private static function signatures(string $class): array
    {
        $out = [];
        $reflection = new \ReflectionClass($class);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== $class && !$reflection->isInterface()) {
                continue;
            }
            $params = [];
            foreach ($m->getParameters() as $p) {
                $param = ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName();
                if ($p->isDefaultValueAvailable()) {
                    $default = $p->getDefaultValue();
                    $param .= ' = ' . ($default === null ? 'null' : ($default === [] ? '[]' : var_export($default, true)));
                }
                $params[] = $param;
            }
            $out[] = $m->getName() . '(' . implode(', ', $params) . ')' . ($m->hasReturnType() ? ': ' . $m->getReturnType() : '');
        }
        return $out;
    }
}
