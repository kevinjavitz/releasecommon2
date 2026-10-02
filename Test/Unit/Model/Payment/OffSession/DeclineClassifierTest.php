<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\DeclineClassifier;
use SalesIgniter\Common\Model\Payment\OffSession\DeclineMapperInterface;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\Model\Payment\OffSession\PaymentDeclinedException;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

/**
 * Moved from releasesubscriptions2 with the class.
 */
class DeclineClassifierTest extends TestCase
{
    public static function messages(): array
    {
        return [
            ['Declined: insufficient funds (2001)', Decline::SOFT, '2001'],
            ['Declined: expired card (2004)', Decline::HARD, '2004'],
            ['Declined: cardholder authentication required (2099)', Decline::ACTION_REQUIRED, '2099'],
            ['Your card was declined. authentication_required', Decline::ACTION_REQUIRED, 'authentication_required'],
            ['The payment method is not available now.', Decline::CONFIG, 'not_available_now'],
            ['Card reported stolen', Decline::HARD, 'stolen'],
            ['Do Not Honor', Decline::SOFT, 'do_not_honor'],
            ['Something odd happened', Decline::SOFT, 'unknown'],
        ];
    }

    #[DataProvider('messages')]
    public function testMessagesAreClassified(string $message, string $class, string $code): void
    {
        $d = (new DeclineClassifier(new MitContext()))->classify(new \Magento\Framework\Exception\LocalizedException(__($message)));
        $this->assertSame($class, $d->getClass(), $message);
        $this->assertSame($code, $d->getCode(), $message);
    }

    public function testAStashedGatewayCodeWinsOverTheMessage(): void
    {
        $context = new MitContext();
        $context->stashGatewayResult(['code' => '2004']);
        $d = (new DeclineClassifier($context))->classify(new \RuntimeException('Transaction declined'));
        $this->assertSame(Decline::HARD, $d->getClass());
    }

    public function testGatewayMappersGoFirst(): void
    {
        $mapper = new class implements DeclineMapperInterface {
            public function map(array $gatewayResult, \Throwable $error): ?Decline
            {
                return new Decline(Decline::CONFIG, 'mapped', 'from the mapper');
            }
        };
        $d = (new DeclineClassifier(new MitContext(), [$mapper]))->classify(new \RuntimeException('insufficient funds'));
        $this->assertSame('mapped', $d->getCode());
    }

    public function testAClassifiedExceptionKeepsItsDecline(): void
    {
        $decline = new Decline(Decline::ACTION_REQUIRED, 'readiness', 'no token');
        $d = (new DeclineClassifier(new MitContext()))->classify(new PaymentDeclinedException(__('no token'), $decline));
        $this->assertSame($decline, $d);
    }

    public function testARevocationIsHardAndFlagged(): void
    {
        $d = (new DeclineClassifier(new MitContext()))->classify(new \RuntimeException('Revocation of authorization order'));
        $this->assertSame(Decline::HARD, $d->getClass());
        $this->assertTrue($d->isRevoked());
    }

    public function testTheStashOfAFailedChargeSurvivesTheEndOfRun(): void
    {
        $context = new MitContext();
        try {
            $context->run(new Subject(), false, function () use ($context) {
                $context->stashGatewayResult(['code' => '2004']);
                throw new \RuntimeException('Transaction declined');
            });
        } catch (\RuntimeException $e) {
            $d = (new DeclineClassifier($context))->classify($e);
        }
        $this->assertSame(Decline::HARD, $d->getClass(), 'the caller classifies after run() has thrown');
    }
}
