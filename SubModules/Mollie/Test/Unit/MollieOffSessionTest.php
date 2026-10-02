<?php
declare(strict_types=1);

namespace SalesIgniter\Common\SubModules\Mollie\Test\Unit;

use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\Decline;
use SalesIgniter\Common\Model\Payment\OffSession\MitContext;
use SalesIgniter\Common\SubModules\Mollie\Model\DeclineMapper;
use SalesIgniter\Common\SubModules\Mollie\Model\MitTransactionPart;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

/**
 * The Mollie sub-module without a Mollie account: the recurring transaction body and the decline
 * classes. The mandate flow itself waits for a test key. Moved from the subscriptions Mollie
 * sub-module.
 */
class MollieOffSessionTest extends TestCase
{
    public function testACustomerCheckoutIsUntouched(): void
    {
        $part = new MitTransactionPart(new MitContext());
        $body = ['method' => 'creditcard', 'cardToken' => 'tkn_x'];
        self::assertSame($body, $part->process($this->createStub(OrderInterface::class), $body));
    }

    public function testAnOffSessionChargeIsARecurringPaymentOnTheMandateWithNoCheckoutFields(): void
    {
        $context = new MitContext();
        $part = new MitTransactionPart($context);
        $s = new Subject(['payment_data' => ['mollie_customer_id' => 'cst_8wmqcHMN4U', 'mollie_mandate_id' => 'mdt_pWUnw6pkBN', 'mollie_method' => 'creditcard']]);
        $out = $context->run($s, false, fn() => $part->process(
            $this->createStub(OrderInterface::class),
            ['method' => 'ideal', 'issuer' => 'ideal_ABNANL2A', 'cardToken' => 'tkn_x', 'payment' => ['cardToken' => 'tkn_x', 'webhookUrl' => 'https://example.test/hook']]
        ));
        self::assertSame('recurring', $out['sequenceType']);
        self::assertSame('cst_8wmqcHMN4U', $out['customerId']);
        self::assertSame('mdt_pWUnw6pkBN', $out['mandateId']);
        self::assertSame('creditcard', $out['method'], 'the mandate\'s method, not the checkout one');
        self::assertArrayNotHasKey('issuer', $out);
        self::assertArrayNotHasKey('cardToken', $out);
        self::assertSame('recurring', $out['payment']['sequenceType'], 'Orders API: also on the embedded payment');
        self::assertArrayNotHasKey('cardToken', $out['payment']);
        self::assertSame('https://example.test/hook', $out['payment']['webhookUrl']);
    }

    public static function declines(): array
    {
        return [
            'insufficient funds' => ['insufficient_funds', Decline::SOFT, false],
            'expired card' => ['card_expired', Decline::HARD, false],
            'authentication' => ['authentication_required', Decline::ACTION_REQUIRED, false],
            'SEPA account closed' => ['AC04', Decline::HARD, false],
            'SEPA no mandate (revoked)' => ['MD01', Decline::HARD, true],
            'SEPA insufficient funds' => ['AM04', Decline::SOFT, false],
        ];
    }

    #[DataProvider('declines')]
    public function testDeclineClasses(string $reason, string $class, bool $revoked): void
    {
        $decline = (new DeclineMapper())->map(['failure_reason' => $reason], new \RuntimeException('Payment failed'));
        self::assertNotNull($decline);
        self::assertSame($class, $decline->getClass());
        self::assertSame($revoked, $decline->isRevoked());
    }

    public function testAMissingMandateNeedsTheCustomer(): void
    {
        $decline = (new DeclineMapper())->map([], new \RuntimeException('The mandate is not valid'));
        self::assertSame(Decline::ACTION_REQUIRED, $decline->getClass());
    }
}
