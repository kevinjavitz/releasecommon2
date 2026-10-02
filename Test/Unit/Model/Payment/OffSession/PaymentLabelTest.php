<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\Payment\OffSession;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Payment\OffSession\PaymentLabel;
use SalesIgniter\Common\Test\Unit\Model\Payment\OffSession\Fixture\Subject;

/**
 * "Visa ending in 4242 (expires 12/2030)": what the customer and the admin see for a saved payment.
 */
class PaymentLabelTest extends TestCase
{
    private function token(string $type, array $details, string $code = 'braintree'): PaymentTokenInterface
    {
        $t = $this->createStub(PaymentTokenInterface::class);
        $t->method('getType')->willReturn($type);
        $t->method('getTokenDetails')->willReturn(json_encode($details));
        $t->method('getPaymentMethodCode')->willReturn($code);
        $t->method('getExpiresAt')->willReturn('2030-12-31 00:00:00');
        return $t;
    }

    private function label(?PaymentTokenInterface $token = null): PaymentLabel
    {
        $repository = $this->createStub(PaymentTokenRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(function () use ($token) {
            if ($token === null) {
                throw new \Magento\Framework\Exception\NoSuchEntityException(__('gone'));
            }
            return $token;
        });
        $method = $this->createStub(MethodInterface::class);
        $method->method('getTitle')->willReturn('Check / Money order');
        $helper = $this->createStub(PaymentHelper::class);
        $helper->method('getMethodInstance')->willReturnCallback(function ($code) use ($method) {
            if ($code !== 'checkmo') {
                throw new \UnexpectedValueException('no method');
            }
            return $method;
        });
        return new PaymentLabel($repository, $helper);
    }

    public function testCardsPaypalAndMethods(): void
    {
        $card = $this->token('card', ['type' => 'VI', 'maskedCC' => '4242', 'expirationDate' => '12/2030']);
        self::assertSame('Visa ending in 4242 (expires 12/2030)', $this->label()->forToken($card));
        self::assertSame('PayPal (a@example.com)', $this->label()->forToken($this->token('account', ['payerEmail' => 'a@example.com'])));
        self::assertSame('Visa ending in 4242 (expires 12/2030)', $this->label($card)->forSubject(new Subject(['vault_token_id' => 7])));
        self::assertSame('Check / Money order', $this->label()->forSubject(new Subject(['vault_token_id' => 7, 'payment_method' => 'checkmo'])), 'deleted token: the method title');
        self::assertSame('gone_method', $this->label()->methodTitle('gone_method'));
        self::assertSame('No payment method', $this->label()->methodTitle(''));
    }

    public function testExpiresBefore(): void
    {
        $card = $this->token('card', []);
        self::assertTrue($this->label()->expiresBefore($card, '2031-01-01 00:00:00'));
        self::assertFalse($this->label()->expiresBefore($card, '2030-01-01 00:00:00'));
    }
}
