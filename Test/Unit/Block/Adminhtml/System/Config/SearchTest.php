<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Block\Adminhtml\System\Config\Search;
use SalesIgniter\Common\Model\Config\Search\IndexBuilder;

/**
 * The search box block: shown on the Sales Igniter sections only, the carried-over term cleaned, the index safe
 * inside a script tag, the widget's words translated server-side.
 *
 * Magento's unit ObjectManager helper makes mocks (object manager, layout) that these tests set no expectation on.
 */
#[AllowMockObjectsWithoutExpectations]
class SearchTest extends TestCase
{
    /** @param array<string, mixed> $params */
    private function block(array $params, ?array $index = null, ?IndexBuilder $builder = null): Search
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default = null) => $params[$key] ?? $default
        );
        if ($builder === null) {
            $index ??= ['sections' => [
                ['id' => 'salesigniter_rental', 'label' => 'Rental', 'url' => 'u1', 'groups' => [], 'fields' => []],
                ['id' => 'sisubscriptions', 'label' => 'Subscriptions', 'url' => 'u2', 'groups' => [], 'fields' => []],
            ]];
            $builder = $this->createStub(IndexBuilder::class);
            $builder->method('build')->willReturn($index);
            $builder->method('sectionIds')->willReturn(array_column($index['sections'], 'id'));
        }
        $om = new ObjectManager($this);
        // Magento\Backend\Block\Template takes its JSON and directory helpers from the application object manager
        $om->prepareObjectManager([
            [\Magento\Framework\Json\Helper\Data::class, $this->createStub(\Magento\Framework\Json\Helper\Data::class)],
            [\Magento\Directory\Helper\Data::class, $this->createStub(\Magento\Directory\Helper\Data::class)],
        ]);
        $context = $om->getObject(Context::class, ['request' => $request]);

        return $om->getObject(Search::class, ['context' => $context, 'indexBuilder' => $builder]);
    }

    public function testASalesIgniterSectionIsSearchable(): void
    {
        self::assertTrue($this->block(['section' => 'sisubscriptions'])->isSearchable());
    }

    public function testAnyOtherSectionIsNot(): void
    {
        self::assertFalse($this->block(['section' => 'catalog'])->isSearchable());
        self::assertFalse($this->block([])->isSearchable());
        self::assertSame('', $this->block(['section' => 'catalog'])->toHtml());
    }

    public function testTheIndexIsBuiltOncePerPageAndNotToAskWhetherThePageIsSearchable(): void
    {
        $builder = $this->createMock(IndexBuilder::class);
        $builder->method('sectionIds')->willReturn(['salesigniter_rental']);
        $builder->expects(self::once())->method('build')->willReturn(['sections' => []]);
        $block = $this->block(['section' => 'salesigniter_rental'], null, $builder);

        self::assertTrue($block->isSearchable());
        $block->getIndexJson();
        $block->getIndex();
    }

    public function testAnotherSectionNeverBuildsTheIndex(): void
    {
        $builder = $this->createMock(IndexBuilder::class);
        $builder->method('sectionIds')->willReturn(['salesigniter_rental']);
        $builder->expects(self::never())->method('build');

        self::assertSame('', $this->block(['section' => 'payment'], null, $builder)->toHtml());
    }

    public function testTheCarriedOverTermIsTrimmedSquashedAndCapped(): void
    {
        self::assertSame('late fee', $this->block(['config_search' => "  late \n\t fee "])->getInitialQuery());
        self::assertSame(100, mb_strlen($this->block(['config_search' => str_repeat('é', 150)])->getInitialQuery()));
        self::assertSame('', $this->block(['config_search' => ['late']])->getInitialQuery());
        self::assertSame('', $this->block([])->getInitialQuery());
    }

    public function testTheIndexCannotCloseItsScriptTag(): void
    {
        $json = $this->block(['section' => 'salesigniter_rental'], ['sections' => [
            ['id' => 's', 'label' => '</script><script>alert(1)</script> & "q" \'a\' <!--', 'groups' => [], 'fields' => []],
        ]])->getIndexJson();

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertSame(
            '</script><script>alert(1)</script> & "q" \'a\' <!--',
            json_decode($json, true)['sections'][0]['label']
        );
    }

    public function testTheSectionOnScreenSendsOnlyWhatItsPageDoesNotShow(): void
    {
        $field = [
            'path' => 's/g/f', 'label' => 'Fee', 'comment' => 'Per day', 'tooltip' => 'Tip', 'hint' => 'Hint',
            'config_path' => 's/other/f', 'depends' => ['s_g_on'],
        ];
        $index = ['sections' => [
            ['id' => 'salesigniter_rental', 'label' => 'Rental', 'url' => 'u1',
                'groups' => [['id' => 's_g', 'label' => 'G', 'comment' => 'About G']], 'fields' => [$field]],
            ['id' => 'sisubscriptions', 'label' => 'Subscriptions', 'url' => 'u2',
                'groups' => [['id' => 's_g', 'label' => 'G', 'comment' => 'About G']], 'fields' => [$field]],
        ]];

        $sent = json_decode($this->block(['section' => 'salesigniter_rental'], $index)->getIndexJson(), true);

        self::assertSame(['path' => 's/g/f', 'config_path' => 's/other/f', 'depends' => ['s_g_on']], $sent['sections'][0]['fields'][0]);
        self::assertSame([['id' => 's_g', 'label' => 'G']], $sent['sections'][0]['groups']);
        // the other sections keep their words: they are searched from the index alone
        self::assertSame($index['sections'][1], $sent['sections'][1]);
    }

    public function testTheWidgetGetsItsSectionAndItsWords(): void
    {
        $config = $this->block(['section' => 'salesigniter_rental'])->getWidgetConfig();

        self::assertSame('salesigniter_rental', $config['section']);
        self::assertSame(Search::QUERY_PARAM, $config['param']);
        self::assertSame(2, $config['minLength']);
        self::assertSame(
            ['countOne', 'countMany', 'noneHere', 'none', 'hintOne', 'hintMany', 'path'],
            array_keys($config['text'])
        );
        self::assertStringContainsString('%1', $config['text']['countMany']);
        self::assertStringContainsString('%2', $config['text']['noneHere']);
        $init = json_decode($this->block(['section' => 'salesigniter_rental'])->getInitJson(), true);
        self::assertSame($config, $init['#si-cfg-search']['SalesIgniter_Common/js/config-search']);
    }
}
