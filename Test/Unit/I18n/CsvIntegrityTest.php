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
 *  - every other locale translates exactly the en_US phrases: none missing, none left over, none
 *    empty, the same placeholders (%1, %name, {{var}}, HTML tags) and the same leading/trailing
 *    whitespace as the source - the code glues some phrases together.
 *
 * Adding a phrase to the code therefore means adding it to en_US AND translating it; that is the
 * point. Plain PHP, no Magento classes: runs in the unit suite or on its own.
 */
namespace SalesIgniter\Common\Test\Unit\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PhraseScanner.php';

class CsvIntegrityTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../../..';

    /** @var array<string, array<int, array<int, string|null>>> */
    private static array $parsed = [];

    public static function i18nDir(): string
    {
        return realpath(self::MODULE_ROOT) . '/i18n';
    }

    /** @return array<string, array{string}> */
    public static function csvFiles(): array
    {
        $out = [];
        foreach (glob(self::i18nDir() . '/*.csv') ?: [] as $file) {
            $out[basename($file)] = [$file];
        }
        return $out;
    }

    /** @return array<string, array{string}> */
    public static function translationFiles(): array
    {
        return array_filter(self::csvFiles(), static fn($f) => basename($f[0]) !== 'en_US.csv');
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
        $this->assertFileExists(self::i18nDir() . '/en_US.csv');
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
        $en = self::pairs(self::i18nDir() . '/en_US.csv');
        $scanner = new PhraseScanner((string) realpath(self::MODULE_ROOT));
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
            'Phrases used in the code but missing from i18n/en_US.csv (add them, then translate them in every locale)'
        );
    }

    public function testEnUsMapsEveryPhraseToItself(): void
    {
        $bad = [];
        foreach (self::pairs(self::i18nDir() . '/en_US.csv') as $k => $v) {
            if ((string) $k !== $v) {
                $bad[] = $k;
            }
        }
        $this->assertSame([], $bad);
    }

    #[DataProvider('translationFiles')]
    public function testTheLocaleTranslatesExactlyTheEnUsPhrases(string $file): void
    {
        $en = self::pairs(self::i18nDir() . '/en_US.csv');
        $tr = self::pairs($file);
        $this->assertSame([], array_map('strval', array_keys(array_diff_key($en, $tr))), 'untranslated (missing) phrases');
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
}
