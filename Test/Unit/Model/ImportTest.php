<?php
declare(strict_types=1);

namespace SalesIgniter\Common\Test\Unit\Model;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\ImportExport\Model\Import as MagentoImport;
use Magento\ImportExport\Model\Import\ErrorProcessing\ProcessingErrorAggregatorInterface;
use Magento\ImportExport\Model\Import\Source\CsvFactory;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SalesIgniter\Common\Model\Import;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

/**
 * The model behind `bin/magento salesigniter:import`.
 *
 * It is a thin wrapper around Magento's own importer (originally
 * cedricblondeau/magento2-module-catalog-import-command) and does only three things of its
 * own: fix the entity and behaviour, validate the file before importing it, and refuse a
 * behaviour that is not one of Magento's four. The third is the one worth a test - an
 * unrecognised behaviour is silently ignored rather than rejected, and "silently ignored"
 * on an importer means the default, which is APPEND.
 *
 * ImportCommand itself is not registered in etc/di.xml, so none of this is reachable from
 * the CLI today. It is still constructible, and Setup/Uninstall.php in releaserental2 has a
 * commented-out reference to it.
 */
class ImportTest extends TestCase
{
    public function testItFixesTheEntityBehaviourAndImageDirectoryOnConstruction(): void
    {
        $captured = [];
        $importModel = $this->createMock(MagentoImport::class);
        $importModel->method('setData')->willReturnCallback(
            function ($key, $value = null) use (&$captured, $importModel) {
                if (is_array($key)) {
                    $captured = $key;
                } else {
                    $captured[$key] = $value;
                }
                return $importModel;
            }
        );

        $this->import($importModel);

        $this->assertSame('catalog_product', $captured['entity']);
        $this->assertSame(MagentoImport::BEHAVIOR_APPEND, $captured['behavior']);
        $this->assertSame('pub/media/catalog/product', $captured[MagentoImport::FIELD_NAME_IMG_FILE_DIR]);
        $this->assertSame(
            ProcessingErrorAggregatorInterface::VALIDATION_STRATEGY_SKIP_ERRORS,
            $captured[MagentoImport::FIELD_NAME_VALIDATION_STRATEGY],
            'the importer skips bad rows rather than aborting the file'
        );
    }

    public function testAMissingFileIsAFileNotFoundExceptionNotAValidationFailure(): void
    {
        $import = $this->import();

        $this->expectException(FileNotFoundException::class);
        $import->setFile('/definitely/not/here.csv');
    }

    public function testAFileThatFailsMagentosValidationIsRejected(): void
    {
        $importModel = $this->createMock(MagentoImport::class);
        $importModel->method('setData')->willReturnSelf();
        $importModel->method('validateSource')->willReturn(false);

        $import = $this->import($importModel);
        $file = tempnam(sys_get_temp_dir(), 'si-import-') . '.csv';
        file_put_contents($file, "sku,name\n");

        try {
            $this->expectException(\InvalidArgumentException::class);
            $import->setFile($file);
        } finally {
            @unlink($file);
        }
    }

    /**
     */
    #[DataProvider('behaviours')]
    public function testItAcceptsMagentosFourBehaviours(string $behavior): void
    {
        $written = [];
        $importModel = $this->createMock(MagentoImport::class);
        $importModel->method('setData')->willReturnCallback(
            function ($key, $value = null) use (&$written, $importModel) {
                if (!is_array($key)) {
                    $written[$key] = $value;
                }
                return $importModel;
            }
        );

        $this->import($importModel)->setBehavior($behavior);

        $this->assertSame($behavior, $written['behavior'] ?? null);
    }

    public static function behaviours(): array
    {
        return [
            'append' => [MagentoImport::BEHAVIOR_APPEND],
            'add/update' => [MagentoImport::BEHAVIOR_ADD_UPDATE],
            'replace' => [MagentoImport::BEHAVIOR_REPLACE],
            'delete' => [MagentoImport::BEHAVIOR_DELETE],
        ];
    }

    /**
     * Pinned, not fixed: an unknown behaviour is dropped on the floor. The command passes
     * --behavior straight through, so `--behavior=destroy` runs an APPEND and says nothing.
     */
    public function testAnUnknownBehaviourIsSilentlyIgnoredRatherThanRejected(): void
    {
        $written = [];
        $importModel = $this->createMock(MagentoImport::class);
        $importModel->method('setData')->willReturnCallback(
            function ($key, $value = null) use (&$written, $importModel) {
                if (!is_array($key)) {
                    $written[$key] = $value;
                }
                return $importModel;
            }
        );

        $this->import($importModel)->setBehavior('destroy_everything');

        $this->assertArrayNotHasKey('behavior', $written);
    }

    public function testASuccessfulImportInvalidatesTheIndexes(): void
    {
        $importModel = $this->createMock(MagentoImport::class);
        $importModel->method('setData')->willReturnSelf();
        $importModel->method('importSource')->willReturn(true);
        $importModel->expects($this->once())->method('invalidateIndex');

        $this->assertTrue($this->import($importModel)->execute());
    }

    public function testAFailedImportLeavesTheIndexesAlone(): void
    {
        $importModel = $this->createMock(MagentoImport::class);
        $importModel->method('setData')->willReturnSelf();
        $importModel->method('importSource')->willReturn(false);
        $importModel->expects($this->never())->method('invalidateIndex');

        $this->assertFalse($this->import($importModel)->execute());
    }

    private function import(?MagentoImport $importModel = null): Import
    {
        if ($importModel === null) {
            $importModel = $this->createMock(MagentoImport::class);
            $importModel->method('setData')->willReturnSelf();
        }

        $csvFactory = $this->createMock(CsvFactory::class);
        $csvFactory->method('create')->willReturn(
            $this->createMock(\Magento\ImportExport\Model\Import\Source\Csv::class)
        );

        return new Import(
            $this->createMock(EavConfig::class),
            $importModel,
            $csvFactory,
            $this->createMock(CollectionFactory::class),
            $this->createMock(ReadFactory::class)
        );
    }
}
