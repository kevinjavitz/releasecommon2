<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Model\Config\Search;

use Magento\Backend\Model\UrlInterface;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\AbstractElement;
use Magento\Config\Model\Config\Structure\Element\AbstractComposite;

/**
 * The settings search's index (Stores > Configuration, the Sales Igniter sections): every field of every section
 * in the configured tabs (default: `salesigniter`, the "Sales Igniter" heading of the left column), read from the
 * system.xml config structure, never from a rendered page.
 *
 * Only what this admin may open, in the scope being edited: the structure's iterators skip sections the admin's
 * role does not allow and groups and fields that are not shown in the current scope (Default Config, a website or
 * a store view), so the counts match what the pages show.
 *
 * Per section: id, label, the URL of its page (current scope kept), its groups (DOM id, label, comment, parent group)
 * and its fields. A field carries its structure path, label, comment, tooltip, hint, `config_path` where it saves
 * somewhere else, and the DOM ids of the fields it depends on (a field hidden by a dependency is found through those).
 * Its own DOM id and its group's are not sent: Magento makes them from the path (domId()), and so does the script.
 * Text is plain: tags stripped, entities decoded, white space squashed. Dynamic comments (a comment model) and
 * tooltip blocks are left out: they need the stored value or render a block; the page in front of the admin has
 * their text in the DOM anyway.
 *
 * The structure hands out flyweights (one object per iterator, its data swapped on each step), so everything is
 * copied out of an element before its children are walked.
 */
class IndexBuilder
{
    public const DEFAULT_TABS = ['salesigniter'];

    /** @var string[] */
    private array $tabs;

    /**
     * @param string[] $tabs config tab ids whose sections are searched (di.xml argument `tabs`)
     */
    public function __construct(
        private readonly Structure $structure,
        private readonly UrlInterface $url,
        array $tabs = self::DEFAULT_TABS
    ) {
        $this->tabs = array_values(array_filter(array_map('strval', $tabs), 'strlen'));
    }

    /**
     * @return array{sections: list<array<string, mixed>>}
     */
    public function build(): array
    {
        $sections = [];
        foreach ($this->structure->getTabs() as $tab) {
            if (!in_array((string)$tab->getId(), $this->tabs, true)) {
                continue;
            }
            foreach ($tab->getChildren() as $section) {
                $sections[] = $this->section($section);
            }
        }

        return ['sections' => $sections];
    }

    /**
     * The ids of the searchable sections, without building the index (is this page one of them?).
     *
     * @return string[]
     */
    public function sectionIds(): array
    {
        $ids = [];
        foreach ($this->structure->getTabs() as $tab) {
            if (!in_array((string)$tab->getId(), $this->tabs, true)) {
                continue;
            }
            foreach ($tab->getChildren() as $section) {
                $ids[] = (string)$section->getId();
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function section(AbstractComposite $section): array
    {
        $id = (string)$section->getId();
        $out = [
            'id' => $id,
            'label' => self::text((string)$section->getLabel()),
            // the left column's own link (Magento\Config\Block\System\Config\Tabs::getSectionUrl): scope kept
            'url' => $this->url->getUrl('*/*/*', ['_current' => true, 'section' => $id]),
            'groups' => [],
            'fields' => [],
        ];
        foreach ($section->getChildren() as $group) {
            $this->group($group, null, $out);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $out the section being built
     */
    private function group(AbstractComposite $group, ?string $parent, array &$out): void
    {
        $path = (string)$group->getPath();
        $domId = self::domId($path);
        $record = ['id' => $domId, 'label' => self::text((string)$group->getLabel())];
        $comment = $this->staticComment($group);
        if ($comment !== '') {
            $record['comment'] = $comment;
        }
        if ($parent !== null) {
            $record['parent'] = $parent;
        }
        $out['groups'][] = $record;

        foreach ($group->getChildren() as $child) {
            $data = (array)$child->getData();
            if (($data['_elementType'] ?? '') === 'group') {
                $this->group($child, $domId, $out);
                continue;
            }
            $field = $this->field($child, $data);
            if ($field !== null) {
                $out['fields'][] = $field;
            }
        }
    }

    /**
     * @param array<string, mixed> $data the element's raw structure data
     * @return array<string, mixed>|null null for a field with neither a label nor a comment (nothing to find)
     */
    private function field(AbstractElement $field, array $data): ?array
    {
        $path = (string)$field->getPath();
        $record = [
            'path' => $path,
            'label' => self::text((string)$field->getLabel()),
        ];
        $comment = $this->staticComment($field);
        if ($comment !== '') {
            $record['comment'] = $comment;
        }
        if ($record['label'] === '' && $comment === '') {
            return null;
        }
        // tooltip_block renders a block: left out (see the class comment)
        if (isset($data['tooltip']) && is_string($data['tooltip']) && method_exists($field, 'getTooltip')) {
            $tooltip = self::text((string)$field->getTooltip());
            if ($tooltip !== '') {
                $record['tooltip'] = $tooltip;
            }
        }
        if (isset($data['hint']) && is_string($data['hint']) && method_exists($field, 'getHint')) {
            $hint = self::text((string)$field->getHint());
            if ($hint !== '') {
                $record['hint'] = $hint;
            }
        }
        $configPath = isset($data['config_path']) && is_string($data['config_path']) ? trim($data['config_path']) : '';
        if ($configPath !== '' && $configPath !== $path) {
            $record['config_path'] = $configPath;
        }
        $depends = $this->depends($data, dirname($path));
        if ($depends) {
            $record['depends'] = $depends;
        }

        return $record;
    }

    /**
     * The DOM ids of the fields this one depends on. The structure's mapper has already made each id a full path
     * (section/group/field); a bare id is taken as a field of the same group.
     *
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function depends(array $data, string $groupPath): array
    {
        $fields = $data['depends']['fields'] ?? null;
        if (!is_array($fields)) {
            return [];
        }
        $ids = [];
        foreach ($fields as $key => $dependency) {
            $target = is_array($dependency) ? (string)($dependency['id'] ?? $key) : (string)$key;
            if ($target === '') {
                continue;
            }
            if (strpos($target, '/') === false) {
                $target = $groupPath . '/' . $target;
            }
            $ids[] = self::domId($target);
        }

        return array_values(array_unique($ids));
    }

    /** A comment given as text (translated); a comment model's comment is left out. */
    private function staticComment(AbstractElement $element): string
    {
        $data = (array)$element->getData();
        if (!isset($data['comment']) || !is_string($data['comment']) || trim($data['comment']) === '') {
            return '';
        }
        // AbstractElement::getComment() translates the attribute; Field::getComment() does the same for text
        return self::text((string)$element->getComment());
    }

    /** section/group/field -> the id Magento's config form gives the element (Form::_generateElementId) */
    public static function domId(string $path): string
    {
        return str_replace('/', '_', $path);
    }

    /** Plain text: tags stripped (a tag boundary stays a space), entities decoded, white space squashed. */
    public static function text(string $html): string
    {
        $text = preg_replace('~<(br|/p|/div|/li|/td|/h\d)\b[^>]*>~i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~[\s\x{00A0}]+~u', ' ', $text) ?? $text;

        return trim($text);
    }
}
