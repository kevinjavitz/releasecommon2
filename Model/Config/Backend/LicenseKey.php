<?php
namespace SalesIgniter\Common\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Framework\Message\ManagerInterface;
use SalesIgniter\Common\Model\License\AccountLink;
use SalesIgniter\Common\Model\License\Manager;

/**
 * Rentals > Settings > License > License key. Stored encrypted and shown masked, like every
 * other secret in Magento's configuration.
 *
 * Saving a new key activates it on this site with rentalbookingsoftware.com and frees the
 * activation the previous key held; clearing the field frees it too. The save itself never fails
 * over the licence server -- a store that cannot reach it still keeps the key, and the status
 * panel says what happened.
 */
class LicenseKey extends Encrypted
{
    private $licenseManager;
    private $messages;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        Manager $licenseManager,
        ManagerInterface $messages,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $encryptor, $resource, $resourceCollection, $data);
        $this->licenseManager = $licenseManager;
        $this->messages = $messages;
    }

    public function beforeSave()
    {
        // a pasted key often carries a space or a line break from wherever it was copied
        $value = (string)$this->getValue();
        if (!preg_match('/^\*+$/', $value)) {
            $this->setValue(trim($value));
        }
        return parent::beforeSave();
    }

    public function afterSave()
    {
        $new = (string)$this->getValue() === '' ? '' : trim((string)$this->_encryptor->decrypt($this->getValue()));
        $oldStored = (string)$this->getOldValue();
        $old = $oldStored === '' ? '' : trim((string)$this->_encryptor->decrypt($oldStored));

        if ($new !== $old) {
            if ($old !== '') {
                $this->licenseManager->deactivate($old);
            }
            if ($new !== '') {
                $this->report($this->licenseManager->activate($new));
            }
        }
        return parent::afterSave();
    }

    private function report(array $status): void
    {
        switch ($status['license']) {
            case Manager::VALID:
                $this->messages->addSuccessMessage(__('Your %1 license is active on this store.', $status['item_name'] ?? __('Sales Igniter')));
                break;
            case Manager::EXPIRED:
                $this->messages->addWarningMessage(__('That license has expired. Renew it from the License panel to keep receiving updates.'));
                break;
            case Manager::UNREACHABLE:
                $this->messages->addWarningMessage(__('The license key was saved, but rentalbookingsoftware.com could not be reached to activate it. Use "Check now" in the License panel to try again.'));
                break;
            case 'no_activations_left':
                $this->messages->addErrorMessage(__('That license is already active on as many sites as it allows. Deactivate it on another site from your account on rentalbookingsoftware.com, then save again.'));
                break;
            case Manager::WRONG_PRODUCT:
                // admin messages are escaped, so the address is plain text here, not a link
                $this->messages->addErrorMessage(__('That key is valid, but not for a Sales Igniter Magento extension. Check that you copied the key for the Magento product from My Account > Licenses (%1).', AccountLink::URL));
                break;
            default:
                $this->messages->addErrorMessage(__('rentalbookingsoftware.com did not recognise that license key (%1). Check it against your keys in My Account > Licenses (%2).', $status['license'], AccountLink::URL));
        }
    }
}
