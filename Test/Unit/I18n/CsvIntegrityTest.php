<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\I18n;

require_once __DIR__ . '/CsvIntegrityTestCase.php';

/**
 * releasecommon2's own i18n CSVs, checked by the shared rules in CsvIntegrityTestCase (file names,
 * Magento's CSV parse, en_US lists every phrase the code uses, every locale translates exactly the
 * en_US phrases with the same placeholders, brand names never translated).
 */
class CsvIntegrityTest extends CsvIntegrityTestCase
{
    protected static function moduleRoot(): string
    {
        return __DIR__ . '/../../..';
    }
}
