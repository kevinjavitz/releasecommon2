<?php
/**
 * The GraphQL helpers moved here from releaserental2 in 1.2.57. Rental's own suite still covers them
 * through its subclasses (Test/Unit/Model/GraphQl, Test/Unit/Model/Resolver); this pins the generic
 * behaviour on the classes themselves, for modules that use them without rental.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\GraphQl;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\GraphQl\Authorization;
use SalesIgniter\Common\Model\GraphQl\DateInput;
use SalesIgniter\Common\Model\GraphQl\Pagination;

class SharedGraphQlHelpersTest extends TestCase
{
    private function authorization(array $granted, bool $throws = false): Authorization
    {
        $acl = $this->createMock(AuthorizationInterface::class);
        $acl->method('isAllowed')->willReturnCallback(function ($resource) use ($granted, $throws) {
            if ($throws) {
                throw new \RuntimeException('no role locator');
            }
            return in_array($resource, $granted, true);
        });
        return new Authorization($acl);
    }

    public function testAssertAndAssertAny(): void
    {
        $auth = $this->authorization(['SalesIgniter_Rfq::quotes']);
        $auth->assert('SalesIgniter_Rfq::quotes', 'rfqQuotes');
        $auth->assertAny(['X::y', 'SalesIgniter_Rfq::quotes'], 'rfqQuote');
        $this->assertTrue($auth->isAllowedAny(['X::y', 'SalesIgniter_Rfq::quotes']));
        $this->assertFalse($auth->isAllowed('SalesIgniter_Rfq::quote_approve'));
        $this->expectException(GraphQlAuthorizationException::class);
        $auth->assert('SalesIgniter_Rfq::quote_approve', 'sendRfqQuote');
    }

    public function testAnAclThatThrowsMeansNo(): void
    {
        $auth = $this->authorization([], true);
        $this->assertFalse($auth->isAllowed('SalesIgniter_Rfq::quotes'));
        $this->expectException(GraphQlAuthorizationException::class);
        $auth->assertAny(['SalesIgniter_Rfq::quotes'], 'rfqQuotes');
    }

    public function testPaginationDefaultsAndLimits(): void
    {
        $pagination = new Pagination();
        $this->assertSame([20, 1], $pagination->fromArgs([]));
        $this->assertSame([50, 3], $pagination->fromArgs(['pageSize' => 50, 'currentPage' => 3]));
        $info = $pagination->pageInfo(45, 20, 2);
        $this->assertSame(3, $info['total_pages']);
        $this->expectException(GraphQlInputException::class);
        $pagination->fromArgs(['pageSize' => Pagination::MAX_PAGE_SIZE + 1]);
    }

    public function testDateInputParsesTheCanonicalFormatsOnly(): void
    {
        $dates = new DateInput();
        $this->assertSame('2027-03-10 00:00:00', $dates->parse('2027-03-10')->format(DateInput::FORMAT_DATETIME));
        $this->assertSame('2027-03-10 14:30:00', $dates->parse('2027-03-10 14:30:00')->format(DateInput::FORMAT_DATETIME));
        $this->expectException(GraphQlInputException::class);
        $dates->parse('03/04/2027');
    }
}
