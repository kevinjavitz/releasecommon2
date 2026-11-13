<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Controller\Adminhtml\License;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Controller\Adminhtml\License\SiteRole;
use SalesIgniter\Common\Model\License\Manager;

/**
 * "Make this the staging site" / "Make this the live site" (1.2.61): the section's own manager moves this store,
 * the admin hears how it went, and the page goes back to the section. A stale form key changes nothing.
 */
class SiteRoleTest extends TestCase
{
    private array $said = [];
    private ?array $redirect = null;

    private function controller(string $section, string $role, array $managers, bool $formKeyOk = true): SiteRole
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($name) => ['section' => $section, 'role' => $role][$name] ?? null);
        $messages = $this->createMock(ManagerInterface::class);
        foreach (['addNoticeMessage', 'addWarningMessage', 'addSuccessMessage', 'addErrorMessage'] as $m) {
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
        $validator = $this->createMock(Validator::class);
        $validator->method('validate')->willReturn($formKeyOk);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getResultRedirectFactory')->willReturn($factory);
        $context->method('getFormKeyValidator')->willReturn($validator);
        return new SiteRole($context, $managers);
    }

    private function manager(array $done): Manager
    {
        $m = $this->createMock(Manager::class);
        $m->method('markSite')->willReturn($done + ['status' => null]);
        return $m;
    }

    public function testTheSectionsManagerMovesThisStoreAndTheAdminIsTold(): void
    {
        $rfq = $this->createMock(Manager::class);
        $rfq->expects($this->once())->method('markSite')->with('staging')->willReturn(['result' => 'staging', 'status' => []]);
        $other = $this->createMock(Manager::class);
        $other->expects($this->never())->method('markSite');

        $this->controller('salesigniter_rfq', 'staging', ['salesigniter_rfq' => $rfq, 'other' => $other])->execute();

        $this->assertSame([['addSuccessMessage', 'This store is now the staging site of its license.']], $this->said);
        $this->assertSame(['adminhtml/system_config/edit', ['section' => 'salesigniter_rfq']], $this->redirect);
    }

    public function testEachOutcomeHasItsOwnWords(): void
    {
        $cases = [
            'live' => 'addSuccessMessage',
            'no_key' => 'addNoticeMessage',
            'inactive' => 'addWarningMessage',
            'busy' => 'addWarningMessage',
            'not_supported' => 'addWarningMessage',
            Manager::UNREACHABLE => 'addWarningMessage',
            'failed' => 'addErrorMessage',
        ];
        $texts = [];
        foreach ($cases as $result => $kind) {
            $this->said = [];
            $this->controller('a', 'live', ['a' => $this->manager(['result' => $result])])->execute();
            $this->assertCount(1, $this->said, $result);
            $this->assertSame($kind, $this->said[0][0], $result);
            $texts[] = $this->said[0][1];
        }
        $this->assertCount(count($cases), array_unique($texts), 'no two outcomes read the same');
    }

    public function testAStaleFormKeyChangesNothing(): void
    {
        $m = $this->createMock(Manager::class);
        $m->expects($this->never())->method('markSite');
        $this->controller('a', 'staging', ['a' => $m], false)->execute();
        $this->assertSame('addErrorMessage', $this->said[0][0]);
        $this->assertSame(['adminhtml/system_config/edit', ['section' => 'a']], $this->redirect);
    }

    public function testAnUnknownSectionOrRoleChangesNothing(): void
    {
        $m = $this->createMock(Manager::class);
        $m->expects($this->never())->method('markSite');
        $this->controller('nobody', 'staging', ['a' => $m])->execute();
        $this->controller('a', 'local', ['a' => $m])->execute();
        $this->assertSame(['addNoticeMessage', 'addNoticeMessage'], array_column($this->said, 0));
    }

    public function testAnUnsafeSectionIsNotEchoedIntoTheRedirect(): void
    {
        $this->controller('x"/><script>', 'live', [])->execute();
        $this->assertSame(['adminhtml/system_config/edit', []], $this->redirect);
    }
}
