<?php
declare(strict_types=1);

/**
 * The i18n CSVs, checked against the code and against Magento's loader.
 *
 * Written after the date-picker phrases turned out to be in no CSV at all - not even en_US - so no
 * store could translate them, and after a locale file shipped as `fi.csv`, which Magento never
 * loads (it reads `<module>/i18n/<locale code>.csv` and nothing else). What is checked:
 *
 *  - every file is named for a Magento locale code, is UTF-8 without a byte-order mark, and parses
 *    the way Magento\Framework\File\Csv parses it (fgetcsv, no escape character) into rows of
 *    exactly two columns, with no source listed twice;
 *  - en_US lists every phrase the code uses (PhraseScanner, which also reads the JS wrappers
 *    Magento's own collector misses) and maps each to itself;
 *  - every other locale lists only en_US phrases (none left over; a phrase it lacks falls back to
 *    English, so a missing translation is allowed), none empty, the same placeholders (%1, %name,
 *    {{var}}, HTML tags) and the same leading/trailing whitespace as the source - the code glues some phrases together;
 *  - brand names are never translated, respelled or declined (Kevin, 2026-09-27; rental master
 *    had shipped "Sales Igniter" as "Verkaufszünder" in every locale).
 *
 * Adding a phrase to the code therefore means adding it to en_US; translating it is optional and
 * never blocks a commit or a release. Plain PHP, no Magento classes: runs in the unit suite or on
 * its own.
 *
 * Carried into releasecommon2 1.2.57 from releaserental2's i18n/refresh branch as an abstract base,
 * so each module's CsvIntegrityTest only names its root.
 */
namespace SalesIgniter\Common\Test\Unit\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PhraseScanner.php';

abstract class CsvIntegrityTestCase extends TestCase
{
    /**
     * The module root (the directory holding i18n/ and registration.php). A module's own test is
     * three lines:
     *
     *     class CsvIntegrityTest extends CsvIntegrityTestCase
     *     {
     *         protected static function moduleRoot(): string { return __DIR__ . '/../../..'; }
     *     }
     */
    abstract protected static function moduleRoot(): string;

    /**
     * Names that stay exactly as written in every language. A phrase containing one must carry it
     * verbatim, at least as often, in every translation: not translated ("Verkaufszünder"), not
     * respelled ("Sales-Igniter-Support"), not declined ("Magenta"). Each entry lists the spellings
     * that count as that brand (the code writes "Hyva", the brand is "Hyvä"). A module can add its
     * own by overriding brands().
     */
    public const BRANDS = [
        'Sales Igniter' => ['Sales Igniter'],
        'SalesIgniter' => ['SalesIgniter'],
        'Magento' => ['Magento'],
        'Hyvä' => ['Hyvä', 'Hyva'],
        'Hyva' => ['Hyva', 'Hyvä'],
        'Luma' => ['Luma'],
        'Amasty' => ['Amasty'],
        'rentalbookingsoftware.com' => ['rentalbookingsoftware.com'],
    ];

    /** @return array<string, string[]> brand => the spellings that count as it */
    protected static function brands(): array
    {
        return self::BRANDS;
    }

    /** @var array<string, array<int, array<int, string|null>>> */
    private static array $parsed = [];

    public static function i18nDir(): string
    {
        return realpath(static::moduleRoot()) . '/i18n';
    }

    /** @return array<string, array{string}> */
    public static function csvFiles(): array
    {
        $out = [];
        foreach (glob(static::i18nDir() . '/*.csv') ?: [] as $file) {
            $out[basename($file)] = [$file];
        }
        return $out;
    }

    /** @return array<string, array{string}> */
    public static function translationFiles(): array
    {
        $files = array_filter(static::csvFiles(), static fn($f) => basename($f[0]) !== 'en_US.csv');
        // an empty data provider is an error in PHPUnit 10; en_US alone then satisfies the checks
        return $files ?: ['en_US.csv' => [static::i18nDir() . '/en_US.csv']];
    }

    /** Rows exactly as Magento\Framework\File\Csv::getData() reads them. */
    private static function rows(string $file): array
    {
        if (!isset(self::$parsed[$file])) {
            $rows = [];
            $fh = fopen($file, 'r');
            while (($row = fgetcsv($fh, 0, ',', '"', "\0")) !== false) {
                $rows[] = $row;
            }
            fclose($fh);
            self::$parsed[$file] = $rows;
        }
        return self::$parsed[$file];
    }

    /** @return array<string, string> source => translation */
    private static function pairs(string $file): array
    {
        $pairs = [];
        foreach (self::rows($file) as $row) {
            if (count($row) === 2 && !array_key_exists((string) $row[0], $pairs)) {
                $pairs[(string) $row[0]] = (string) $row[1];
            }
        }
        return $pairs;
    }

    /**
     * %1..%n, email-template variables (%order_number), {{...}}, sprintf %s/%d and HTML tag names,
     * as a sorted multiset.
     *
     * @return string[]
     */
    public static function placeholders(string $text): array
    {
        $found = [];
        preg_match_all('~%\d+|%[a-z_]+(?:\.[a-z_]+)*|\{\{[^}]*\}\}|<[^>]+>~', $text, $m);
        foreach ($m[0] as $token) {
            if ($token[0] === '<') {
                preg_match_all('~%\d+|%[a-z_]+~', $token, $inner);
                array_push($found, ...$inner[0]);
                $token = preg_match('~^<\s*(/?)\s*([a-zA-Z0-9]+)~', $token, $tag)
                    ? '<' . $tag[1] . strtolower($tag[2]) . '>'
                    : $token;
            }
            $found[] = $token;
        }
        sort($found);
        return $found;
    }

    public function testThereIsAnEnUsFile(): void
    {
        $this->assertFileExists(static::i18nDir() . '/en_US.csv');
    }

    #[DataProvider('csvFiles')]
    public function testTheFileIsNamedForALocaleMagentoLoads(string $file): void
    {
        $this->assertMatchesRegularExpression(
            '~^[a-z]{2,3}(?:_[A-Z][a-z]{3})?_[A-Z]{2}\.csv$~',
            basename($file),
            'Magento only loads i18n/<locale code>.csv (fi_FI.csv, zh_Hans_CN.csv); a file named anything else is ignored'
        );
    }

    #[DataProvider('csvFiles')]
    public function testTheFileIsUtf8WithoutAByteOrderMark(string $file): void
    {
        $raw = (string) file_get_contents($file);
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $raw, 'a BOM becomes part of the first source phrase');
        $this->assertTrue(mb_check_encoding($raw, 'UTF-8'), 'not valid UTF-8');
    }

    #[DataProvider('csvFiles')]
    public function testEveryRowParsesAsExactlyTwoColumns(string $file): void
    {
        $bad = [];
        foreach (self::rows($file) as $i => $row) {
            if (count($row) !== 2) {
                $bad[] = 'row ' . ($i + 1) . ': ' . count($row) . ' columns: ' . substr(implode(' | ', array_map('strval', $row)), 0, 100);
            }
        }
        $this->assertSame([], $bad);
    }

    #[DataProvider('csvFiles')]
    public function testNoSourcePhraseIsListedTwice(string $file): void
    {
        $seen = [];
        $dupes = [];
        foreach (self::rows($file) as $row) {
            $key = (string) ($row[0] ?? '');
            if (isset($seen[$key])) {
                $dupes[] = $key;
            }
            $seen[$key] = true;
        }
        $this->assertSame([], $dupes);
    }

    #[DataProvider('csvFiles')]
    public function testNoPhraseCarriesADoubledQuoteThatMagentoWouldCollapse(string $file): void
    {
        // Magento\Framework\Translate::_addData() runs str_replace('""', '"') over key and value
        $bad = [];
        foreach (self::pairs($file) as $k => $v) {
            $k = (string) $k; // PHP turns a numeric phrase ("10") into an int key
            if (str_contains($k, '""') || str_contains($v, '""')) {
                $bad[] = $k;
            }
        }
        $this->assertSame([], $bad);
    }

    public function testEnUsListsEveryPhraseTheCodeUses(): void
    {
        $en = self::pairs(static::i18nDir() . '/en_US.csv');
        $scanner = new PhraseScanner((string) realpath(static::moduleRoot()));
        $missing = [];
        foreach ($scanner->scan() as $phrase => $where) {
            $phrase = (string) $phrase;
            if (!array_key_exists($phrase, $en)) {
                $missing[] = $phrase . '   <- ' . $where[0];
            }
        }
        $this->assertSame(
            [],
            $missing,
            'Phrases used in the code but missing from i18n/en_US.csv (add them; translating them into the other locales is optional)'
        );
    }

    public function testEnUsMapsEveryPhraseToItself(): void
    {
        $bad = [];
        foreach (self::pairs(static::i18nDir() . '/en_US.csv') as $k => $v) {
            if ((string) $k !== $v) {
                $bad[] = $k;
            }
        }
        $this->assertSame([], $bad);
    }

    #[DataProvider('translationFiles')]
    public function testTheLocaleListsOnlyEnUsPhrases(string $file): void
    {
        $en = self::pairs(static::i18nDir() . '/en_US.csv');
        $tr = self::pairs($file);
        $this->assertSame([], array_map('strval', array_keys(array_diff_key($tr, $en))), 'phrases not in en_US (dead, or en_US is missing them)');
    }

    #[DataProvider('translationFiles')]
    public function testEveryTranslationIsNonEmptyAndKeepsPlaceholdersAndEdgeSpaces(string $file): void
    {
        $bad = [];
        foreach (self::pairs($file) as $src => $tr) {
            $src = (string) $src;
            if (trim($tr) === '') {
                $bad[] = 'empty: ' . $src;
                continue;
            }
            if (self::placeholders($src) !== self::placeholders($tr)) {
                $bad[] = 'placeholders: ' . $src . ' => ' . $tr;
            }
            if (ctype_space($src[0] ?? 'x') !== ctype_space($tr[0] ?? 'x')
                || ctype_space(substr($src, -1)) !== ctype_space(substr($tr, -1))) {
                $bad[] = 'leading/trailing space: "' . $src . '" => "' . $tr . '"';
            }
        }
        $this->assertSame([], $bad);
    }

    /** @return string[] the brands $src names that $tr does not carry verbatim, as often */
    public static function brandViolations(string $src, string $tr): array
    {
        $out = [];
        foreach (static::brands() as $brand => $spellings) {
            $want = substr_count($src, $brand);
            if ($want === 0) {
                continue;
            }
            $have = 0;
            foreach ($spellings as $spelling) {
                $have += substr_count($tr, $spelling);
            }
            if ($have < $want) {
                $out[] = $brand;
            }
        }
        return $out;
    }

    #[DataProvider('translationFiles')]
    public function testBrandNamesAreNeverTranslated(string $file): void
    {
        $bad = [];
        foreach (self::pairs($file) as $src => $tr) {
            foreach (static::brandViolations((string) $src, $tr) as $brand) {
                $bad[] = $brand . ': "' . $src . '" => "' . $tr . '"';
            }
        }
        $this->assertSame([], $bad, 'brand names stay exactly as written in every language');
    }
}
