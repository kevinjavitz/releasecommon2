<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Controller\Adminhtml\License;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use SalesIgniter\Common\Model\License\Manager;

/**
 * "Check now" in an extension's License panel: re-asks rentalbookingsoftware.com about the key saved
 * in that extension's settings section, then returns to the section.
 *
 * GET salesigniter_common/license/refresh/section/<config section>. Each extension registers the
 * licence manager for its own section in di.xml (the `managers` argument); a section nobody
 * registered answers with a notice and changes nothing. releaserental2 keeps its own
 * salesigniter_rental/license/refresh for its section.
 */
class Refresh extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Config::config';

    /** @var array<string, Manager> */
    private array $managers = [];

    /**
     * @param Manager[] $managers keyed by config section id
     */
    public function __construct(Action\Context $context, array $managers = [])
    {
        parent::__construct($context);
        foreach ($managers as $section => $manager) {
            if ($manager instanceof Manager) {
                $this->managers[(string)$section] = $manager;
            }
        }
    }

    public function execute()
    {
        $section = (string)$this->getRequest()->getParam('section');
        $manager = $this->managers[$section] ?? null;
        if ($manager === null) {
            $this->messageManager->addNoticeMessage(__('There is no license to check here.'));
        } else {
            $status = $manager->status(true);
            if ($status === null) {
                $this->messageManager->addNoticeMessage(__('Enter a license key first.'));
            } elseif ($status['license'] === Manager::UNREACHABLE || !empty($status['unreachable_at'])) {
                $this->messageManager->addWarningMessage(__('rentalbookingsoftware.com could not be reached. Try again in a moment.'));
            } else {
                $this->messageManager->addSuccessMessage(__('License checked.'));
            }
        }
        $params = $section !== '' && preg_match('/^[a-z0-9_]+$/', $section) ? ['section' => $section] : [];
        return $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit', $params);
    }
}
