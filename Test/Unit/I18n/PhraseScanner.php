<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\I18n;

/**
 * Finds every translatable phrase a module actually uses, so the i18n CSVs can be checked
 * against the code rather than against each other.
 *
 * `bin/magento i18n:collect-phrases` is the starting point, but it only understands `__()`,
 * `new Phrase()`, `$t()` / `$.mage.__()`, `{{trans}}`, knockout `i18n:` bindings and XML
 * `translate` attributes. This scanner covers those plus what that command misses here:
 *
 *  - JS wrappers around `$t` -- a local `function T(key)` / `function tr(s, args)` that hands
 *    its argument to `$t()`. They exist to survive prototype.js (see the report scripts), and
 *    Magento's collector never sees the phrases passed to them;
 *  - `$t()` / `$.mage.__()` inside `.phtml` inline scripts;
 *  - `email_templates.xml` labels, `menu.xml` titles and `acl.xml` titles, which Magento puts
 *    through `__()` when it renders them whether or not the file says `translate=`.
 *
 * Pure PHP, no Magento classes, so it runs in any unit harness and as a plain script.
 */
class PhraseScanner
{
    /** Directories (relative to the module root) that ship no runtime phrases. */
    private const SKIP_DIRS = ['Test', 'test', 'tests', 'docs', 'dev', 'i18n', 'node_modules', 'vendor', '.git'];

    /** @var array<string, array<string, true>> phrase => set of "file:line" */
    private array $phrases = [];

    /** @var string[] human-readable notes about calls whose phrase cannot be read statically */
    private array $dynamic = [];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * @return array<string, string[]> phrase => where it is used, in first-seen order
     */
    public function scan(): array
    {
        $this->phrases = [];
        $this->dynamic = [];
        foreach ($this->files() as $file) {
            $rel = substr($file, strlen(rtrim($this->root, '/')) + 1);
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $content = (string) file_get_contents($file);
            switch ($ext) {
                case 'php':
                    $this->scanPhp($content, $rel);
                    break;
                case 'phtml':
                    $this->scanPhp($content, $rel);
                    $this->scanJs($content, $rel);
                    break;
                case 'js':
                    if (!$this->isVendoredJs($rel, $content)) {
                        $this->scanJs($content, $rel);
                    }
                    break;
                case 'html':
                    $this->scanHtml($content, $rel);
                    break;
                case 'xml':
                    $this->scanXml($content, $rel);
                    break;
            }
        }
        $out = [];
        foreach ($this->phrases as $phrase => $where) {
            $out[(string) $phrase] = array_keys($where);
        }
        return $out;
    }

    /** @return string[] calls whose phrase is not a literal (for a human to follow up) */
    public function dynamicCalls(): array
    {
        return $this->dynamic;
    }

    /** @return string[] */
    private function files(): array
    {
        $root = rtrim($this->root, '/');
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $f) use ($root): bool {
                    if ($f->isDir()) {
                        $rel = substr($f->getPathname(), strlen($root) + 1);
                        return !in_array($rel, self::SKIP_DIRS, true) && $f->getFilename() !== '.git';
                    }
                    return in_array(strtolower($f->getExtension()), ['php', 'phtml', 'js', 'html', 'xml'], true);
                }
            )
        );
        foreach ($it as $f) {
            $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    /** Third-party libraries bundled as-is (minified or with a licence banner) carry no phrases. */
    private function isVendoredJs(string $rel, string $content): bool
    {
        if (preg_match('~\.min\.js$~', $rel)) {
            return true;
        }
        // One enormous line near the top is a minified build whatever it is called.
        foreach (array_slice(explode("\n", $content), 0, 60) as $line) {
            if (strlen($line) > 3000) {
                return true;
            }
        }
        return false;
    }

    private function add(string $phrase, string $file, int $line): void
    {
        if ($phrase === '' || trim($phrase) === '') {
            return;
        }
        $this->phrases[$phrase][$file . ':' . $line] = true;
    }

    // ---------------------------------------------------------------- PHP

    private function scanPhp(string $content, string $file): void
    {
        $tokens = token_get_all($content);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            $isCall = $t[0] === T_STRING && $t[1] === '__' && !$this->isMemberOrDeclaration($tokens, $i);
            $isNew = false;
            if ($t[0] === T_NEW) {
                $j = $this->nextReal($tokens, $i);
                if ($j !== null && is_array($tokens[$j])) {
                    $name = ltrim($tokens[$j][1], '\\');
                    $isNew = in_array($name, ['Phrase', 'Magento\\Framework\\Phrase'], true);
                    if ($isNew) {
                        $i = $j;
                    }
                }
            }
            if (!$isCall && !$isNew) {
                continue;
            }
            $open = $this->nextReal($tokens, $i);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }
            [$arg, $end] = $this->firstArgument($tokens, $open);
            $literal = $this->literal($arg);
            $line = $t[2];
            if ($literal === null) {
                $src = trim(implode('', array_map(static fn($x) => is_array($x) ? $x[1] : $x, $arg)));
                if ($src !== '') {
                    $this->dynamic[] = $file . ':' . $line . '  __(' . $src . ')';
                }
            } else {
                $this->add($literal, $file, $line);
            }
            $i = $end;
        }
    }

    /** `$x->__(`, `Foo::__(` and `function __(` are not the translate function. */
    private function isMemberOrDeclaration(array $tokens, int $i): bool
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            $p = $tokens[$k];
            if (is_array($p) && in_array($p[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($p)) {
                return in_array($p[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
            }
            return false;
        }
        return false;
    }

    private function nextReal(array $tokens, int $i): ?int
    {
        $n = count($tokens);
        for ($k = $i + 1; $k < $n; $k++) {
            $p = $tokens[$k];
            if (is_array($p) && in_array($p[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $k;
        }
        return null;
    }

    /**
     * @return array{0: array, 1: int} tokens of the first argument, index of the token ending it
     */
    private function firstArgument(array $tokens, int $open): array
    {
        $depth = 0;
        $arg = [];
        $n = count($tokens);
        for ($k = $open + 1; $k < $n; $k++) {
            $p = $tokens[$k];
            $s = is_array($p) ? $p[1] : $p;
            if (in_array($s, ['(', '[', '{'], true) || (is_array($p) && in_array($p[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($s, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return [$arg, $k];
                }
                $depth--;
            } elseif ($s === ',' && $depth === 0) {
                return [$arg, $k];
            }
            $arg[] = $p;
        }
        return [$arg, $n - 1];
    }

    /** A literal, or literals joined by `.`; null for anything computed. */
    private function literal(array $arg): ?string
    {
        $parts = [];
        $expectString = true;
        foreach ($arg as $p) {
            if (is_array($p) && in_array($p[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($expectString && is_array($p) && $p[0] === T_CONSTANT_ENCAPSED_STRING) {
                $parts[] = $this->decodePhpString($p[1]);
                $expectString = false;
                continue;
            }
            if (!$expectString && $p === '.') {
                $expectString = true;
                continue;
            }
            return null;
        }
        return ($parts && !$expectString) ? implode('', $parts) : null;
    }

    private function decodePhpString(string $lit): string
    {
        $q = $lit[0];
        $body = substr($lit, 1, -1);
        if ($q === "'") {
            return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
        }
        return (string) preg_replace_callback(
            '~\\\\(n|t|r|v|e|f|\\\\|\$|"|[0-7]{1,3}|x[0-9A-Fa-f]{1,2}|u\{[0-9A-Fa-f]+\})~',
            static function (array $m): string {
                $e = $m[1];
                $map = ['n' => "\n", 't' => "\t", 'r' => "\r", 'v' => "\v", 'e' => "\e", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
                if (isset($map[$e])) {
                    return $map[$e];
                }
                if ($e[0] === 'x') {
                    return chr(hexdec(substr($e, 1)));
                }
                if ($e[0] === 'u') {
                    return mb_chr(hexdec(substr($e, 2, -1)), 'UTF-8');
                }
                return chr(octdec($e));
            },
            $body
        );
    }

    // ---------------------------------------------------------------- JS

    private function scanJs(string $content, string $file): void
    {
        // Magento joins 'a' + 'b' before reading phrases; do the same.
        $joined = (string) preg_replace('~(["\'])\s*\+\s*\1~', '', $content);
        $str = '(["\'])((?:\\\\.|(?!\1)[^\\\\\n])*)\1';
        $patterns = [
            '~(?<![\w.])\$t\(\s*' . $str . '\s*[,)]~',
            '~(?:\$|jQuery)\.mage\.__\(\s*' . $str . '\s*[,)]~',
        ];
        // Local wrappers: function NAME(arg, ...) { ... $t(arg) ... }
        if (preg_match_all('~function\s+([A-Za-z_][\w]*)\s*\(\s*([A-Za-z_$][\w$]*)[^)]*\)\s*\{[^{}]{0,400}?\$t\(\s*\2\s*\)~', $content, $m)) {
            foreach (array_unique($m[1]) as $name) {
                $patterns[] = '~(?<![\w$.])' . preg_quote($name, '~') . '\(\s*' . $str . '\s*[,)]~';
            }
        }
        foreach ($patterns as $re) {
            if (!preg_match_all($re, $joined, $mm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }
            foreach ($mm as $hit) {
                $line = substr_count($joined, "\n", 0, $hit[0][1]) + 1;
                $this->add($this->decodeJsString($hit[2][0]), $file, $line);
            }
        }
        // $t( / T( with a computed argument: note it so a human can list the phrases it can receive.
        if (preg_match_all('~(?<![\w$.])(\$t|T|tr)\(\s*(?!["\'\s)])([^)\n]{1,80})~', $joined, $dm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($dm as $hit) {
                $arg = $hit[2][0];
                if (preg_match('~^(key|s|text|str|msg|k)\s*$~', $arg)) {
                    continue; // the wrapper's own body
                }
                $line = substr_count($joined, "\n", 0, $hit[0][1]) + 1;
                $this->dynamic[] = $file . ':' . $line . '  ' . $hit[1][0] . '(' . $arg;
            }
        }
    }

    private function decodeJsString(string $body): string
    {
        return (string) preg_replace_callback(
            '~\\\\(u[0-9A-Fa-f]{4}|x[0-9A-Fa-f]{2}|.)~s',
            static function (array $m): string {
                $e = $m[1];
                if ($e[0] === 'u' && strlen($e) === 5) {
                    return mb_chr(hexdec(substr($e, 1)), 'UTF-8');
                }
                if ($e[0] === 'x' && strlen($e) === 3) {
                    return chr(hexdec(substr($e, 1)));
                }
                return ['n' => "\n", 't' => "\t", 'r' => "\r"][$e] ?? $e;
            },
            $body
        );
    }

    // ---------------------------------------------------------------- HTML (knockout + email templates)

    private function scanHtml(string $content, string $file): void
    {
        $lineAt = static fn(int $off): int => substr_count($content, "\n", 0, $off) + 1;
        // {{trans "..."}} / {{trans '...'}}, read the way Magento\Framework\Filter\Template does:
        // the phrase runs to the LAST matching quote that is followed by whitespace or the end, so
        // {{trans "see <a href="%url">here</a>" url=$x}} is one phrase, quotes and all.
        if (preg_match_all('~\{\{trans\b(.*?)\}\}~si', $content, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if (preg_match('~^\s*([\'"])([^\1]*?)(?<!\\\\)\1(\s.*)?$~si', $hit[1][0], $d)) {
                    $this->add($d[2], $file, $lineAt($hit[0][1]));
                }
            }
        }
        $res = [
            '~i18n:\s*\'((?:\\\\.|[^\'\\\\])*)\'~',
            '~i18n:\s*"((?:\\\\.|[^"\\\\])*)"~',
            '~translate(?: args)?="\'((?:\\\\.|[^"\\\\])*)\'"~',
        ];
        foreach ($res as $re) {
            if (preg_match_all($re, $content, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $this->add($this->decodeJsString($hit[1][0]), $file, $lineAt($hit[0][1]));
                }
            }
        }
        $this->scanJs($content, $file);
    }

    // ---------------------------------------------------------------- XML

    private function scanXml(string $content, string $file): void
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($content, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return;
        }
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[@translate or @translatable]') as $el) {
            /** @var \DOMElement $el */
            $spec = $el->getAttribute('translate');
            if ($spec === 'true' || $el->getAttribute('translatable') === 'true') {
                $this->add($el->textContent, $file, $el->getLineNo());
                continue;
            }
            foreach (preg_split('~[\s,]+~', trim($spec)) as $name) {
                if ($name === '') {
                    continue;
                }
                if ($el->hasAttribute($name)) {
                    $this->add($el->getAttribute($name), $file, $el->getLineNo());
                }
                foreach ($el->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->nodeName === $name) {
                        $this->add(trim($child->textContent), $file, $child->getLineNo());
                    }
                }
            }
        }
        // <head><title> in layout files
        foreach ($xpath->query('/*/head/title') as $el) {
            $this->add(trim($el->textContent), $file, $el->getLineNo());
        }
        // Translated at render whatever the translate attribute says: email template labels
        // (Magento\Email\Model\Template\Config::getTemplateLabel), admin menu titles
        // (Magento\Backend\Block\Menu / AnchorRenderer) and ACL resource titles (the role tree,
        // Magento\Integration\Helper\Data::mapResources).
        $always = [
            'email_templates.xml' => ['//template[@label]', 'label'],
            'menu.xml' => ['//add[@title] | //update[@title]', 'title'],
            'acl.xml' => ['//resource[@title]', 'title'],
        ];
        if (isset($always[basename($file)])) {
            [$query, $attr] = $always[basename($file)];
            foreach ($xpath->query($query) as $el) {
                $this->add($el->getAttribute($attr), $file, $el->getLineNo());
            }
        }
    }
}
