<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Controller\Adminhtml\License;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Controller\Adminhtml\License\Refresh;
use SalesIgniter\Common\Model\License\Manager;

class RefreshTest extends TestCase
{
    private array $said = [];
    private ?array $redirect = null;

    private function controller(string $section, array $managers): Refresh
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('section')->willReturn($section);
        $messages = $this->createMock(ManagerInterface::class);
        foreach (['addNoticeMessage', 'addWarningMessage', 'addSuccessMessage'] as $m) {
            $messages->method($m)->willReturnCallback(function ($text) use ($m, $messages) {
                $this->said[] = [$m, (string)$text];
                return $messages;
            });
        }
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path, $params = []) use ($redirect) {
            $this->redirect = [$path, $params];
            return $redirect;
        });
        $factory = $this->createMock(RedirectFactory::class);
        $factory->method('create')->willReturn($redirect);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($factory);
        return new Refresh($context, $managers);
    }

    public function testItForcesARecheckOfTheSectionsOwnManagerAndReturnsToTheSection(): void
    {
        $rfq = $this->createMock(Manager::class);
        $rfq->expects($this->once())->method('status')->with(true)->willReturn(['license' => 'valid']);
        $other = $this->createMock(Manager::class);
        $other->expects($this->never())->method('status');

        $this->controller('salesigniter_rfq', ['salesigniter_rfq' => $rfq, 'other' => $other])->execute();

        $this->assertSame([['addSuccessMessage', 'License checked.']], $this->said);
        $this->assertSame(['adminhtml/system_config/edit', ['section' => 'salesigniter_rfq']], $this->redirect);
    }

    public function testAnUnregisteredSectionChangesNothing(): void
    {
        $this->controller('nobody', [])->execute();
        $this->assertSame('addNoticeMessage', $this->said[0][0]);
        $this->assertSame(['adminhtml/system_config/edit', ['section' => 'nobody']], $this->redirect);
    }

    public function testAnUnsafeSectionIsNotEchoedIntoTheRedirect(): void
    {
        $this->controller('x"/><script>', [])->execute();
        $this->assertSame(['adminhtml/system_config/edit', []], $this->redirect);
    }

    public function testNoKeyAndUnreachableAreReported(): void
    {
        $none = $this->createMock(Manager::class);
        $none->method('status')->willReturn(null);
        $this->controller('a', ['a' => $none])->execute();
        $down = $this->createMock(Manager::class);
        $down->method('status')->willReturn(['license' => Manager::UNREACHABLE]);
        $this->controller('b', ['b' => $down])->execute();
        $this->assertSame(['addNoticeMessage', 'addWarningMessage'], array_column($this->said, 0));
    }
}
