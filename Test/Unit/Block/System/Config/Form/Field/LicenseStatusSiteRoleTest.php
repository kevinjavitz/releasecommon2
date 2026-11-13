<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Block\System\Config\Form\Field;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Block\System\Config\Form\Field\LicenseStatus;
use SalesIgniter\Common\Model\License\AccountLink;
use SalesIgniter\Common\Model\License\Manager;

/**
 * The License panel's live / staging part (1.2.61): this store's label and the button that moves it, the store's
 * own words, and "This is a staging site" while saving the key would activate it.
 */
class LicenseStatusSiteRoleTest extends TestCase
{
    /** @var ObjectManagerInterface|null the instance before this test (restored after) */
    private $previous;

    protected function setUp(): void
    {
        try {
            $this->previous = ObjectManager::getInstance();
        } catch (\RuntimeException $e) {
            $this->previous = null;
        }
        // the parent blocks fetch a few helpers from the object manager
        $om = $this->createMock(ObjectManagerInterface::class);
        $om->method('get')->willReturnCallback(fn ($class) => $this->createMock($class));
        ObjectManager::setInstance($om);
    }

    protected function tearDown(): void
    {
        $prop = new \ReflectionProperty(ObjectManager::class, '_instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $this->previous);
    }

    private function html(?array $status, string $mode = State::MODE_PRODUCTION): string
    {
        $manager = $this->getMockBuilder(Manager::class)->disableOriginalConstructor()
            ->onlyMethods(['status', 'renewalUrl', 'daysLeft', 'section'])->getMock();
        $manager->method('status')->willReturn($status);
        $manager->method('renewalUrl')->willReturn('');
        $manager->method('daysLeft')->willReturn(null);
        $manager->method('section')->willReturn('salesigniter_rfq');
        $link = $this->createMock(AccountLink::class);
        $link->method('sentence')->willReturnCallback(fn ($text) => htmlspecialchars((string)$text));
        $state = $this->createMock(State::class);
        $state->method('getMode')->willReturn($mode);

        $block = new class ($this->createMock(Context::class), $manager, [], $link,
            'salesigniter_common/license/refresh', ['section' => 'salesigniter_rfq'], $state) extends LicenseStatus {
            public function escapeHtml($data, $allowedTags = null)
            {
                return htmlspecialchars((string)$data, ENT_QUOTES);
            }

            public function escapeHtmlAttr($string, $escapeSingleQuote = true)
            {
                return htmlspecialchars((string)$string, ENT_QUOTES);
            }

            public function escapeUrl($string)
            {
                return htmlspecialchars((string)$string, ENT_QUOTES);
            }

            public function getUrl($route = '', $params = [])
            {
                return 'https://shop.example.com/admin/' . $route . '/?' . http_build_query($params);
            }

            public function getFormKey()
            {
                return 'FORMKEY';
            }

            public function formatDate($date = null, $format = \IntlDateFormatter::SHORT, $showTime = false, $timezone = null)
            {
                return 'a moment ago';
            }

            public function panelHtml(AbstractElement $element): string
            {
                return $this->_getElementHtml($element);
            }
        };
        return $block->panelHtml($this->createMock(AbstractElement::class));
    }

    private static function status(string $license, array $more = []): array
    {
        return $more + ['license' => $license, 'item_id' => 32867, 'item_name' => 'Magento 2 Rental Booking System Pro',
            'expires' => 'lifetime', 'license_limit' => 2, 'site_count' => 1, 'key_tail' => '6789', 'checked_at' => 1790121600];
    }

    private static function labelled(string $role, int $coversStaging = 1, string $license = Manager::VALID): array
    {
        return self::status($license, ['si_site_role' => $role,
            'si_sites' => ['live' => 1, 'staging' => 0, 'local' => 0, 'covers_live' => 1, 'covers_staging' => $coversStaging]]);
    }

    public function testALiveSiteGetsItsLabelAndTheButtonToMakeItStaging(): void
    {
        $html = $this->html(self::labelled('live'));
        $this->assertStringContainsString('This store:', $html);
        $this->assertStringContainsString('Live site', $html);
        $this->assertStringContainsString('1 live site + 1 staging site', $html);
        $this->assertStringContainsString('Make this the staging site', $html);
        $this->assertStringContainsString('admin/salesigniter_common/license/siterole/?section=salesigniter_rfq&amp;role=staging&amp;form_key=FORMKEY', $html);
        $this->assertStringNotContainsString('si_site_staging', $html, 'no checkbox once the key is active here');
    }

    public function testAStagingSiteGetsTheButtonToMakeItLive(): void
    {
        $html = $this->html(self::labelled('staging'));
        $this->assertStringContainsString('Staging site', $html);
        $this->assertStringContainsString('Make this the live site', $html);
        $this->assertStringContainsString('role=live', $html);
    }

    public function testNoButtonForALocalCopyALicenceWithoutStagingOrAnInactiveKey(): void
    {
        $this->assertStringNotContainsString('siterole', $this->html(self::labelled('local')));
        $this->assertStringContainsString('Local copy', $this->html(self::labelled('local')));
        $html = $this->html(self::labelled('live', 0));
        $this->assertStringContainsString('1 live site', $html);
        $this->assertStringNotContainsString('staging site', $html);
        $this->assertStringNotContainsString('siterole', $html);
        $this->assertStringNotContainsString('siterole', $this->html(self::labelled('live', 1, Manager::EXPIRED)));
    }

    public function testAStoreWithoutLabelsShowsThePanelAsBefore(): void
    {
        $html = $this->html(self::status(Manager::VALID));
        $this->assertStringNotContainsString('This store:', $html);
        $this->assertStringNotContainsString('siterole', $html);
        $this->assertStringNotContainsString('si_site_staging', $html);
        $this->assertStringContainsString('Check now', $html);
    }

    public function testTheCheckboxShowsUntilTheKeyIsActiveHere(): void
    {
        foreach ([null, self::status(Manager::SITE_INACTIVE), self::status('no_activations_left')] as $status) {
            $html = $this->html($status);
            $this->assertStringContainsString('<input type="hidden" name="si_site_role_shown" value="1">', $html);
            $this->assertStringContainsString('name="si_site_staging" value="1">', $html, 'unticked');
            $this->assertStringContainsString('This is a staging site', $html);
        }
    }

    public function testTheCheckboxStartsTickedWhenTheLiveSpotIsUsedOrInDeveloperMode(): void
    {
        $taken = self::status(Manager::SITE_INACTIVE, ['si_site_role' => '',
            'si_sites' => ['live' => 1, 'staging' => 0, 'local' => 0, 'covers_live' => 1, 'covers_staging' => 1]]);
        $this->assertStringContainsString('value="1" checked="checked">', $this->html($taken));
        $this->assertStringContainsString('value="1" checked="checked">', $this->html(null, State::MODE_DEVELOPER));
        $this->assertStringNotContainsString('checked', $this->html(null, State::MODE_DEFAULT), 'default mode is often a live store');
    }

    public function testTheStoresOwnWordsAreShownAsText(): void
    {
        $html = $this->html(self::status('no_activations_left', ['si_message' => 'In use on shop.example.org & <b>1</b> more.']));
        $this->assertStringContainsString('In use on shop.example.org &amp; &lt;b&gt;1&lt;/b&gt; more.', $html);
    }
}
