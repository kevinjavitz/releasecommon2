<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Setup\ModuleRemoval;

use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Setup\ModuleRemoval\SystemConfigReader;

/**
 * Which core_config_data rows a module owns. The rental module created its section, so the whole
 * section goes; an add-on only added groups to it, so only its own groups go, and in a group it
 * shares with another module only its own fields.
 */
class SystemConfigReaderTest extends TestCase
{
    /**
     * These cases read the rental module's real files (the engine moved here from releaserental2 in
     * 1.2.57); they are skipped where that module is not installed.
     */
    private static function rentalRoot(): string
    {
        $path = (new \Magento\Framework\Component\ComponentRegistrar())->getPath(
            \Magento\Framework\Component\ComponentRegistrar::MODULE,
            'SalesIgniter_Rental'
        );
        if (!$path) {
            self::markTestSkipped('SalesIgniter_Rental is not installed');
        }
        return $path;
    }

    public function testTheRentalModuleOwnsItsWholeSection(): void
    {
        $reader = new SystemConfigReader();
        $mine = $reader->read((string)file_get_contents(self::rentalRoot() . '/etc/adminhtml/system.xml'));

        $this->assertSame(['salesigniter_rental'], $mine['sections']);
        $this->assertContains('salesigniter_rental/calendar_options/picker', $mine['fields'], 'a config_path is honoured');
        $plan = $reader->pathsToDelete($mine, ['salesigniter_rental/emails', 'catalog/frontend']);
        $this->assertSame(['salesigniter_rental/'], $plan['prefixes']);
        $this->assertSame([], $plan['exact']);
    }

    public function testAnAddOnOwnsItsGroupsButOnlyItsFieldsInASharedGroup(): void
    {
        $xml = <<<XML
<config>
  <system>
    <section id="salesigniter_rental">
      <group id="maintenance"><field id="enabled"/><group id="inner"><field id="x"/></group></group>
      <group id="emails"><field id="maintenance_template"/></group>
      <group id="moved"><field id="f"><config_path>carriers/si/f</config_path></field></group>
    </section>
  </system>
</config>
XML;
        $reader = new SystemConfigReader();
        $mine = $reader->read($xml);
        $this->assertSame([], $mine['sections'], 'no <tab>: the section belongs to somebody else');

        $plan = $reader->pathsToDelete($mine, ['salesigniter_rental/emails', 'salesigniter_rental/moved']);
        $this->assertSame(['salesigniter_rental/maintenance/'], $plan['prefixes']);
        $this->assertSame(['salesigniter_rental/emails/maintenance_template', 'carriers/si/f'], $plan['exact']);
    }
}
