<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Controller\Adminhtml\License;

use Magento\Framework\App\Action\HttpGetActionInterface;
use SalesIgniter\Common\Model\License\Manager;

/**
 * "Make this the staging site" / "Make this the live site" in an extension's License panel (1.2.61): asks
 * rentalbookingsoftware.com to move this store between the live and the staging spot of the key saved in that
 * section, then returns to the section.
 *
 * GET salesigniter_common/license/siterole/section/<config section>/role/<live|staging>/form_key/<form key>.
 * The managers are Refresh's: the `managers` di argument of Refresh reaches this subclass too (Magento merges a
 * parent type's arguments into its children), and etc/di.xml adds the rental section's for this route.
 */
class SiteRole extends Refresh implements HttpGetActionInterface
{
    public function execute()
    {
        $section = (string)$this->getRequest()->getParam('section');
        $role = (string)$this->getRequest()->getParam('role');
        $params = $section !== '' && preg_match('/^[a-z0-9_]+$/', $section) ? ['section' => $section] : [];
        $redirect = $this->resultRedirectFactory->create()->setPath('adminhtml/system_config/edit', $params);

        if (!$this->_formKeyValidator->validate($this->getRequest())) {
            $this->messageManager->addErrorMessage(__('This page had expired. Open the License panel again and retry.'));
            return $redirect;
        }
        $manager = $this->managers[$section] ?? null;
        if ($manager === null || !in_array($role, [Manager::ROLE_LIVE, Manager::ROLE_STAGING], true)) {
            $this->messageManager->addNoticeMessage(__('There is no license to change here.'));
            return $redirect;
        }

        $done = $manager->markSite($role);
        switch ($done['result'] ?? 'failed') {
            case Manager::ROLE_LIVE:
                $this->messageManager->addSuccessMessage(__('This store is now the live site of its license.'));
                break;
            case Manager::ROLE_STAGING:
                $this->messageManager->addSuccessMessage(__('This store is now the staging site of its license.'));
                break;
            case 'no_key':
                $this->messageManager->addNoticeMessage(__('Enter a license key first.'));
                break;
            case 'inactive':
                $this->messageManager->addWarningMessage(__('The license is not active on this store. Save the key to activate it here first.'));
                break;
            case 'busy':
                $this->messageManager->addWarningMessage(__('This license was changed many times in the last few minutes. Try again in 10 minutes.'));
                break;
            case 'not_supported':
                $this->messageManager->addWarningMessage(__('This license does not cover a staging site.'));
                break;
            case Manager::UNREACHABLE:
                $this->messageManager->addWarningMessage(__('rentalbookingsoftware.com could not be reached. Try again in a moment.'));
                break;
            default:
                $this->messageManager->addErrorMessage(__('rentalbookingsoftware.com did not make the change. Try again, or contact Sales Igniter support.'));
        }
        return $redirect;
    }
}
