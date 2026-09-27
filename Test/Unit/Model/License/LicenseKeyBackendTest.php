<?php
/**
 * Saving Rentals > Settings > License > License key: a new key is activated here and the one it
 * replaces is freed; clearing the field frees it; saving the page without touching the field
 * (the masked "******" comes back) does neither.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\License;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Config\Backend\LicenseKey;
use SalesIgniter\Common\Model\License\Manager;

class LicenseKeyBackendTest extends TestCase
{
    private $activated = [];
    private $deactivated = [];
    private $messages = [];

    private function backend(string $stored): LicenseKey
    {
        $events = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $context = $this->createMock(\Magento\Framework\Model\Context::class);
        $context->method('getEventDispatcher')->willReturn($events);
        $context->method('getCacheManager')->willReturn($this->createMock(\Magento\Framework\App\CacheInterface::class));
        $context->method('getActionValidator')->willReturn($this->createMock(\Magento\Framework\Model\ActionValidator\RemoveAction::class));
        $config = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($stored === '' ? '' : 'enc:' . $stored);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(fn ($v) => 'enc:' . $v);
        $encryptor->method('decrypt')->willReturnCallback(fn ($v) => substr((string)$v, 4));
        $manager = $this->createMock(Manager::class);
        $manager->method('activate')->willReturnCallback(function ($key) {
            $this->activated[] = $key;
            return ['license' => Manager::VALID, 'item_name' => 'Magento 2 Rental Booking System Pro'];
        });
        $manager->method('deactivate')->willReturnCallback(function ($key) { $this->deactivated[] = $key; });
        $messages = $this->createMock(ManagerInterface::class);
        foreach (['addSuccessMessage', 'addWarningMessage', 'addErrorMessage'] as $m) {
            $messages->method($m)->willReturnCallback(function ($text) use ($m, $messages) { $this->messages[] = [$m, (string)$text]; return $messages; });
        }
        $backend = new LicenseKey(
            $context,
            $this->createMock(\Magento\Framework\Registry::class),
            $config,
            $this->createMock(\Magento\Framework\App\Cache\TypeListInterface::class),
            $encryptor,
            $manager,
            $messages,
            $this->createMock(\Magento\Framework\Model\ResourceModel\AbstractResource::class)
        );
        $backend->setPath(Manager::CONFIG_KEY)->setScope('default')->setScopeId(0);
        return $backend;
    }

    private function save(LicenseKey $backend, string $posted): void
    {
        $backend->setValue($posted);
        $backend->beforeSave();
        $backend->afterSave();
    }

    public function testANewKeyIsActivatedAndTheOldOneFreed(): void
    {
        $this->save($this->backend('OLDKEY'), "  NEWKEY\n");
        $this->assertSame(['OLDKEY'], $this->deactivated);
        $this->assertSame(['NEWKEY'], $this->activated, 'the pasted whitespace is trimmed before activating');
        $this->assertSame('addSuccessMessage', $this->messages[0][0]);
    }

    public function testAFirstKeyIsActivatedWithNothingToFree(): void
    {
        $this->save($this->backend(''), 'NEWKEY');
        $this->assertSame([], $this->deactivated);
        $this->assertSame(['NEWKEY'], $this->activated);
    }

    public function testClearingTheFieldFreesTheKey(): void
    {
        $this->save($this->backend('OLDKEY'), '');
        $this->assertSame(['OLDKEY'], $this->deactivated);
        $this->assertSame([], $this->activated);
    }

    public function testResavingTheSameKeyDoesNothing(): void
    {
        $this->save($this->backend('SAMEKEY'), 'SAMEKEY');
        $this->assertSame([], $this->deactivated);
        $this->assertSame([], $this->activated);
    }

    public function testTheMaskedValueIsNotSaved(): void
    {
        $backend = $this->backend('SAMEKEY');
        $backend->setValue('******');
        $backend->beforeSave();
        $this->assertFalse($backend->isSaveAllowed(), 'Magento skips the save, so afterSave never runs');
    }
}
