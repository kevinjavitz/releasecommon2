<?php
/**
 * The license help text points to My Account > Licenses on rentalbookingsoftware.com, as a link
 * that opens in a new tab, instead of "your order email" -- on the License key note, in the
 * Status panel with no key, a key for another product and an unrecognised key, and (as plain
 * text, because admin messages are escaped) in the messages shown after saving a key.
 */
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model\License;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Block\System\Config\Form\Field\LicenseStatus;
use SalesIgniter\Common\Model\Config\Backend\LicenseKey;
use SalesIgniter\Common\Model\Config\Comment\LicenseKey as LicenseKeyComment;
use SalesIgniter\Common\Model\License\AccountLink;
use SalesIgniter\Common\Model\License\Manager;

class AccountLinkTest extends TestCase
{
    private const LINK = '<a href="https://rentalbookingsoftware.com/my-account/licenses/" target="_blank" rel="noopener">My Account &gt; Licenses</a>';

    /**
     * The real Escaper. Its escapeUrl() asks the global object manager for the inline translator;
     * hand it one here rather than installing a global object manager other tests would inherit.
     */
    private function escaper(): Escaper
    {
        $escaper = new Escaper();
        $inline = $this->createStub(\Magento\Framework\Translate\InlineInterface::class);
        $property = new \ReflectionProperty(Escaper::class, 'translateInline');
        $property->setValue($escaper, $inline);

        return $escaper;
    }

    private function link(): AccountLink
    {
        return new AccountLink($this->escaper());
    }

    public function testTheLinkOpensMyAccountLicensesInANewTab(): void
    {
        $this->assertSame('https://rentalbookingsoftware.com/my-account/licenses/', AccountLink::URL);
        $this->assertSame(self::LINK, $this->link()->html());
    }

    public function testASentenceIsEscapedWholeAndOnlyThenLinked(): void
    {
        $html = $this->link()->sentence(__('Keys <b>here</b>: %1 & more', AccountLink::TOKEN));

        $this->assertSame('Keys &lt;b&gt;here&lt;/b&gt;: ' . self::LINK . ' &amp; more', $html, 'markup in the text is escaped, the link is not');
    }

    public function testTheLicenseKeyNoteLinksMyAccountLicenses(): void
    {
        $note = (new LicenseKeyComment($this->link()))->getCommentText('0:3:encrypted');

        $this->assertStringContainsString(self::LINK, $note);
        $this->assertStringNotContainsString('order email', $note);
    }

    /**
     * @return string the Status panel's HTML for a given licence answer
     */
    private function statusPanel(?array $status): string
    {
        $manager = $this->createStub(Manager::class);
        $manager->method('status')->willReturn($status);
        $manager->method('renewalUrl')->willReturn('');
        $manager->method('daysLeft')->willReturn(null);

        // built without its constructor (a backend block's constructor wants the whole admin
        // context); panel() needs only these
        $reflection = new \ReflectionClass(LicenseStatus::class);
        $block = $reflection->newInstanceWithoutConstructor();
        $set = function (string $class, string $name, $value) use ($block) {
            $property = new \ReflectionProperty($class, $name);
            $property->setValue($block, $value);
        };
        // the panel's code lives in releasecommon2 since 1.2.57; the properties are declared there
        $set(\SalesIgniter\Common\Block\System\Config\Form\Field\LicenseStatus::class, 'licenseManager', $manager);
        $set(\SalesIgniter\Common\Block\System\Config\Form\Field\LicenseStatus::class, 'accountLink', $this->link());
        $set(\Magento\Framework\View\Element\AbstractBlock::class, '_escaper', $this->escaper());
        $set(\Magento\Framework\View\Element\AbstractBlock::class, '_urlBuilder', $this->createStub(\Magento\Framework\UrlInterface::class));
        $set(\Magento\Framework\View\Element\AbstractBlock::class, '_localeDate', $this->createStub(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class));
        $method = new \ReflectionMethod($block, '_getElementHtml');

        return (string)$method->invoke($block, $this->createStub(AbstractElement::class));
    }

    public function testWithNoKeyTheStatusPanelLinksMyAccountLicenses(): void
    {
        $html = $this->statusPanel(null);

        $this->assertStringContainsString(self::LINK, $html);
        $this->assertStringContainsString('Enter your license key above and save.', $html);
        $this->assertStringNotContainsString('order email', $html);
    }

    public function testAKeyForAnotherProductPointsToMyAccountLicenses(): void
    {
        $html = $this->statusPanel(['license' => Manager::WRONG_PRODUCT]);

        $this->assertStringContainsString(self::LINK, $html);
        $this->assertStringNotContainsString('order email', $html);
    }

    public function testAnUnrecognisedKeyPointsToMyAccountLicensesAndEscapesTheCode(): void
    {
        $html = $this->statusPanel(['license' => '<invalid>']);

        $this->assertStringContainsString(self::LINK, $html);
        $this->assertStringContainsString('(&lt;invalid&gt;)', $html, 'the code from the server is escaped once');
        $this->assertStringNotContainsString('order email', $html);
    }

    /** @return string[] the messages a save produced */
    private function messagesAfterSaving(string $answer): array
    {
        $said = [];
        $context = $this->createStub(\Magento\Framework\Model\Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(\Magento\Framework\Event\ManagerInterface::class));
        $context->method('getCacheManager')->willReturn($this->createStub(\Magento\Framework\App\CacheInterface::class));
        $config = $this->createStub(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('');
        $encryptor = $this->createStub(\Magento\Framework\Encryption\EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(fn ($v) => 'enc:' . $v);
        $encryptor->method('decrypt')->willReturnCallback(fn ($v) => substr((string)$v, 4));
        $manager = $this->createStub(Manager::class);
        $manager->method('activate')->willReturn(['license' => $answer]);
        $messages = $this->createStub(ManagerInterface::class);
        foreach (['addSuccessMessage', 'addWarningMessage', 'addErrorMessage'] as $m) {
            $messages->method($m)->willReturnCallback(function ($text) use (&$said, $messages) {
                $said[] = (string)$text;

                return $messages;
            });
        }
        $backend = new LicenseKey(
            $context,
            $this->createStub(\Magento\Framework\Registry::class),
            $config,
            $this->createStub(\Magento\Framework\App\Cache\TypeListInterface::class),
            $encryptor,
            $manager,
            $messages,
            $this->createStub(\Magento\Framework\Model\ResourceModel\AbstractResource::class)
        );
        $backend->setPath(Manager::CONFIG_KEY)->setScope('default')->setScopeId(0);
        $backend->setValue('NEWKEY');
        $backend->beforeSave();
        $backend->afterSave();

        return $said;
    }

    public function testTheSaveMessagesGiveTheAddressAsPlainText(): void
    {
        foreach ([Manager::WRONG_PRODUCT, 'invalid'] as $answer) {
            $said = $this->messagesAfterSaving($answer);
            $this->assertCount(1, $said, $answer);
            $this->assertStringContainsString('My Account > Licenses (' . AccountLink::URL . ')', $said[0], $answer);
            $this->assertStringNotContainsString('<a ', $said[0], 'no raw HTML in an escaped admin message');
            $this->assertStringNotContainsString('order email', $said[0]);
        }
    }

    public function testNoLicenseTextInTheModuleStillSendsCustomersToTheirOrderEmail(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([
            'Block/System/Config/Form/Field/LicenseStatus.php',
            'Model/Config/Backend/LicenseKey.php',
            'Model/Config/Comment/LicenseKey.php',
        ] as $file) {
            $this->assertStringNotContainsStringIgnoringCase('order email', (string)file_get_contents($root . '/' . $file), $file);
        }
        // which system.xml field uses the comment model is each extension's own test
    }
}
