<?php
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Braintree\Test\Unit;

use PayPal\Braintree\Gateway\Request\ThreeDSecureVaultDataBuilder;
use PayPal\Braintree\Gateway\Request\TransactionSourceDataBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\SubModules\Braintree\Model\DeclineMapper;
use SalesIgniter\Common\SubModules\Braintree\Plugin\ThreeDSecureVaultPlugin;
use SalesIgniter\Common\SubModules\Braintree\Plugin\TransactionSourcePlugin;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

/**
 * The Braintree sub-module without a Braintree account: the MIT flags on an off-session charge and
 * the decline classes. Moved from the subscriptions Braintree sub-module (the first-order
 * recurring_first flag stays there: it is a subscription rule).
 */
class BraintreeOffSessionTest extends TestCase
{
    public function testAnOffSessionChargeIsSentAsRecurringOrUnscheduled(): void
    {
        $context = new MitContext();
        $plugin = new TransactionSourcePlugin($context);
        $builder = $this->createStub(TransactionSourceDataBuilder::class);

        $fixed = $context->run(new Subject(), false, fn() => $plugin->afterBuild($builder, [], []));
        self::assertSame('recurring', $fixed[TransactionSourceDataBuilder::TRANSACTION_SOURCE]);
        $variable = $context->run(new Subject(), true, fn() => $plugin->afterBuild($builder, [], []));
        self::assertSame('unscheduled', $variable[TransactionSourceDataBuilder::TRANSACTION_SOURCE]);
    }

    public function testCustomerCheckoutsAreUntouched(): void
    {
        $plugin = new TransactionSourcePlugin(new MitContext());
        self::assertSame([], $plugin->afterBuild($this->createStub(TransactionSourceDataBuilder::class), [], []));
    }

    public function testNoThreeDSecureChallengeIsAskedForOffSession(): void
    {
        $context = new MitContext();
        $plugin = new ThreeDSecureVaultPlugin($context);
        $builder = $this->createStub(ThreeDSecureVaultDataBuilder::class);
        $request = ['options' => ['threeDSecure' => ['required' => true]]];
        self::assertSame($request, $plugin->afterBuild($builder, $request, []), 'customer present: unchanged');
        self::assertSame([], $context->run(new Subject(), false, fn() => $plugin->afterBuild($builder, $request, [])));
    }

    public static function declines(): array
    {
        return [
            'insufficient funds' => [['gateway' => 'braintree', 'code' => '2001', 'text' => 'Insufficient Funds'], Decline::SOFT],
            'do not honor' => [['gateway' => 'braintree', 'code' => '2000', 'text' => 'Do Not Honor'], Decline::SOFT],
            'expired card' => [['gateway' => 'braintree', 'code' => '2004', 'text' => 'Expired Card'], Decline::HARD],
            'closed card' => [['gateway' => 'braintree', 'code' => '2012', 'text' => 'Closed Card'], Decline::HARD],
            '3DS required' => [['gateway' => 'braintree', 'code' => '2099', 'text' => 'Cardholder Authentication Required'], Decline::ACTION_REQUIRED],
            '3DS rejection' => [['gateway' => 'braintree', 'gateway_rejection' => 'three_d_secure'], Decline::ACTION_REQUIRED],
            'fraud rejection' => [['gateway' => 'braintree', 'gateway_rejection' => 'fraud'], Decline::SOFT],
            'merchant setup' => [['gateway' => 'braintree', 'gateway_rejection' => 'application_incomplete'], Decline::CONFIG],
        ];
    }

    #[DataProvider('declines')]
    public function testDeclineClasses(array $result, string $class): void
    {
        $decline = (new DeclineMapper())->map($result, new \RuntimeException('declined'));
        self::assertNotNull($decline);
        self::assertSame($class, $decline->getClass());
    }

    public function testOtherGatewaysAreNotMapped(): void
    {
        self::assertNull((new DeclineMapper())->map(['gateway' => 'adyen', 'code' => '2001'], new \RuntimeException('x')));
    }
}
