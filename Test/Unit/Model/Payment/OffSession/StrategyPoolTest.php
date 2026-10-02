<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\OffSessionMethods;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyInterface;
use SalesIgniter\Common\Model\Payment\OffSession\StrategyPool;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

class StrategyPoolTest extends TestCase
{
    private function strategy(string $code): StrategyInterface
    {
        $s = $this->createStub(StrategyInterface::class);
        $s->method('getCode')->willReturn($code);
        return $s;
    }

    private function pool(): StrategyPool
    {
        $methods = $this->createStub(OffSessionMethods::class);
        $methods->method('strategyFor')->willReturnMap([
            ['braintree_cc_vault', 1, 'vault'],
            ['checkmo', 1, 'offline'],
            ['paypal_express', 1, null],
            ['stripe_payments', 1, 'stripe'],
        ]);
        return new StrategyPool($methods, [$this->strategy('vault'), $this->strategy('offline'), 'not a strategy']);
    }

    public function testASubjectUsesTheStrategyItWasSavedWithElseItsMethod(): void
    {
        $pool = $this->pool();
        self::assertSame('offline', $pool->forSubject(new Subject(['payment_method' => 'braintree_cc_vault', 'payment_data' => ['strategy' => 'offline']]))->getCode());
        self::assertSame('vault', $pool->forSubject(new Subject(['payment_method' => 'braintree_cc_vault']))->getCode());
        self::assertSame('vault', $pool->forSubject(new Subject(['payment_method' => 'braintree_cc_vault', 'payment_data' => ['strategy' => 'gone']]))->getCode(), 'an uninstalled stored strategy falls back to the method');
        self::assertNull($pool->forSubject(new Subject(['payment_method' => 'paypal_express'])));
    }

    public function testAMappedMethodWhoseSubModuleIsMissingHasNoStrategy(): void
    {
        self::assertNull($this->pool()->forCheckoutMethod('stripe_payments', 1), 'stripe mapped, SalesIgniter_CommonPayStripe not enabled');
        self::assertFalse($this->pool()->has('stripe'));
        self::assertTrue($this->pool()->has('vault'));
    }

    public function testGettingAnUninstalledStrategyThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->pool()->get('mollie');
    }
}
