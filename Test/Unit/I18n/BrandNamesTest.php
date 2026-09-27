<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CsvIntegrityTestCase.php';

/**
 * Brand names are never translated (Kevin, 2026-09-27), checked over this module's own CSVs with
 * the rule every module gets from CsvIntegrityTestCase::testBrandNamesAreNeverTranslated().
 *
 * releasecommon2 has no full CsvIntegrityTest of its own yet: run over this module, the base's
 * "en_US lists every phrase the code uses" check reports 11 phrases of the GraphQL helpers and
 * ModuleRemoval (moved here for 1.2.57) missing from en_US.csv, which is a separate job.
 */
class BrandNamesTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function translationFiles(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/../../../i18n/*.csv') ?: [] as $file) {
            if (basename($file) !== 'en_US.csv') {
                $out[basename($file)] = [$file];
            }
        }
        return $out ?: ['none' => ['']];
    }

    #[DataProvider('translationFiles')]
    public function testBrandNamesAreNeverTranslated(string $file): void
    {
        if ($file === '') {
            $this->markTestSkipped('no locale files');
        }
        $bad = [];
        $fh = fopen($file, 'r');
        // parsed as Magento\Framework\File\Csv does: fgetcsv, no escape character
        while (($row = fgetcsv($fh, 0, ',', '"', "\0")) !== false) {
            if (count($row) !== 2) {
                continue;
            }
            foreach (CsvIntegrityTestCase::brandViolations((string)$row[0], (string)$row[1]) as $brand) {
                $bad[] = $brand . ': "' . $row[0] . '" => "' . $row[1] . '"';
            }
        }
        fclose($fh);
        $this->assertSame([], $bad, 'brand names stay exactly as written in every language');
    }

    public function testTheBrandRuleCatchesWhatItIsFor(): void
    {
        $this->assertSame(['Sales Igniter'], CsvIntegrityTestCase::brandViolations('Sales Igniter', 'Verkaufszünder'));
        $this->assertSame(['Sales Igniter'], CsvIntegrityTestCase::brandViolations('Contact Sales Igniter support.', 'Wenden Sie sich an den Sales-Igniter-Support.'));
        $this->assertSame(['Magento'], CsvIntegrityTestCase::brandViolations('clear all Magento caches', 'očistite svu predmemoriju Magenta'));
        $this->assertSame([], CsvIntegrityTestCase::brandViolations('Force Hyva Theme Mode', 'Pakota Hyvä-teematila'));
        $this->assertSame([], CsvIntegrityTestCase::brandViolations('your Magento extension', 'Ihre Magento-Erweiterung'));
    }
}
