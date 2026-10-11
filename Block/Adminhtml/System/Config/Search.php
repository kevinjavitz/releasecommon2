<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use SalesIgniter\Common\Model\Config\Search\IndexBuilder;

/**
 * The search box at the top of the Sales Igniter settings (Stores > Configuration, every section of the
 * `salesigniter` tab: Rental, Request for Quote, Subscriptions, ...). It filters the page as the admin types
 * (view/adminhtml/web/js/config-search.js) and lists the other Sales Igniter sections that match, from the index
 * IndexBuilder reads out of the config structure. Renders nothing on any other section.
 *
 * `?config_search=<term>` on a section URL pre-fills the box (the "Also in" links carry the term that way).
 */
class Search extends Template
{
    public const QUERY_PARAM = 'config_search';
    public const MIN_LENGTH = 2;
    private const MAX_QUERY = 100;

    /** @var string */
    protected $_template = 'SalesIgniter_Common::system/config/search.phtml';

    /** @var array{sections: list<array<string, mixed>>}|null */
    private ?array $index = null;

    public function __construct(
        Context $context,
        private readonly IndexBuilder $indexBuilder,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** The section being edited (AbstractConfig::dispatch() puts the first section in when the URL has none). */
    public function getSectionId(): string
    {
        return (string)$this->getRequest()->getParam('section');
    }

    /** @return array{sections: list<array<string, mixed>>} */
    public function getIndex(): array
    {
        if ($this->index === null) {
            $this->index = $this->indexBuilder->build();
        }

        return $this->index;
    }

    /** Is the page one of the searchable sections? (Asked on every config page: the index is only built for one.) */
    public function isSearchable(): bool
    {
        $section = $this->getSectionId();

        return $section !== '' && in_array($section, $this->indexBuilder->sectionIds(), true);
    }

    /** The term carried over from another section's "Also in" link, if any. */
    public function getInitialQuery(): string
    {
        $query = $this->getRequest()->getParam(self::QUERY_PARAM);
        if (!is_string($query)) {
            return '';
        }
        $query = trim(preg_replace('~\s+~u', ' ', $query) ?? '');

        return mb_substr($query, 0, self::MAX_QUERY);
    }

    /**
     * The index for the page's `<script type="application/json">`. The section on screen keeps only what its page
     * does not show (paths, dependencies): its labels and help text are read from the page itself. No `<` or `>`
     * survives the encoding, so the data cannot close its script tag.
     */
    public function getIndexJson(): string
    {
        $index = $this->getIndex();
        $current = $this->getSectionId();
        foreach ($index['sections'] as &$section) {
            if (($section['id'] ?? null) !== $current) {
                continue;
            }
            foreach ($section['fields'] as &$field) {
                unset($field['label'], $field['comment'], $field['tooltip'], $field['hint']);
            }
            unset($field);
            foreach ($section['groups'] as &$group) {
                unset($group['comment']);
            }
            unset($group);
        }
        unset($section);

        return self::json($index);
    }

    /**
     * The widget's options (text/x-magento-init), with every word it shows already translated.
     *
     * @return array<string, mixed>
     */
    public function getWidgetConfig(): array
    {
        return [
            'section' => $this->getSectionId(),
            'param' => self::QUERY_PARAM,
            'minLength' => self::MIN_LENGTH,
            'indexId' => 'si-cfg-search-index',
            'text' => [
                'countOne' => (string)__('1 setting matches'),
                'countMany' => (string)__('%1 settings match'),
                'noneHere' => (string)__('No settings match "%1" in %2.'),
                'none' => (string)__('No settings match "%1".'),
                'hintOne' => (string)__('Change this to show 1 more matching setting.'),
                'hintMany' => (string)__('Change this to show %1 more matching settings.'),
                'path' => (string)__('Setting path'),
            ],
        ];
    }

    /** JSON for a text/x-magento-init block (same escaping as the index). */
    public function getInitJson(): string
    {
        return self::json(['#si-cfg-search' => ['SalesIgniter_Common/js/config-search' => $this->getWidgetConfig()]]);
    }

    /** JSON for inside a script element: `<` and `>` as \u003C / \u003E, everything else as short as it goes. */
    private static function json(array $data): string
    {
        return (string)json_encode(
            $data,
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    protected function _toHtml()
    {
        if (!$this->isSearchable()) {
            return '';
        }

        return parent::_toHtml();
    }
}
