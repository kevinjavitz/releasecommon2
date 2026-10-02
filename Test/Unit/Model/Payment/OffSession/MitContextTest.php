<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession;

use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

/**
 * MitContext: what the gateway plugins and observers see while a merchant-initiated charge runs.
 */
class MitContextTest extends TestCase
{
    public function testItIsActiveOnlyInsideRunAndRestoresWhatWasThere(): void
    {
        $context = new MitContext();
        $outer = new Subject(['reference' => 'outer']);
        $inner = new Subject(['reference' => 'inner']);
        self::assertFalse($context->isActive());
        $seen = $context->run($outer, false, function () use ($context, $inner) {
            $nested = $context->run($inner, true, fn() => [$context->subject(), $context->isVariableAmount(), $context->context('k')], ['k' => 'v']);
            return [$nested, $context->subject(), $context->isVariableAmount(), $context->context('k', 'none')];
        });
        self::assertSame([$inner, true, 'v'], $seen[0]);
        self::assertSame([$outer, false, 'none'], array_slice($seen, 1), 'the outer charge is back after the nested one');
        self::assertFalse($context->isActive());
        self::assertNull($context->subject());
        self::assertSame([], $context->context());
    }

    public function testItIsClearedWhenTheWorkThrows(): void
    {
        $context = new MitContext();
        try {
            $context->run(new Subject(), true, function () {
                throw new \RuntimeException('declined');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('declined', $e->getMessage());
        }
        self::assertFalse($context->isActive());
        self::assertFalse($context->isVariableAmount());
    }

    public function testTheOrderEmailIsSuppressedOnlyWhenAskedAndOnlyInsideRun(): void
    {
        $context = new MitContext();
        self::assertFalse($context->run(new Subject(), false, fn() => $context->suppressesOrderEmail()));
        self::assertTrue($context->run(new Subject(), false, fn() => $context->suppressesOrderEmail(), [MitContext::SUPPRESS_ORDER_EMAIL => true]));
        self::assertFalse($context->suppressesOrderEmail());
    }

    public function testTheGatewayStashIsResetOnEntryAndKeptAfterwards(): void
    {
        $context = new MitContext();
        $context->stashGatewayResult(['code' => 'old']);
        $context->run(new Subject(), false, function () use ($context) {
            self::assertSame([], $context->gatewayResult(), 'a new charge starts with an empty stash');
            $context->stashGatewayResult(['code' => '2001']);
        });
        self::assertSame(['code' => '2001'], $context->gatewayResult());
        $context->clearGatewayResult();
        self::assertSame([], $context->gatewayResult());
    }
}
