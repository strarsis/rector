<?php

declare(strict_types=1);

namespace Rector\Testing\PHPUnit;

use Iterator;
use Nette\Utils\Strings;
use PHPStan\Analyser\NodeScopeResolver;
use Rector\Core\Application\FileProcessor;
use Rector\Core\Application\FileSystem\RemovedAndAddedFilesCollector;
use Rector\Core\Bootstrap\RectorConfigsResolver;
use Rector\Core\Configuration\Option;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\HttpKernel\RectorKernel;
use Rector\Core\NonPhpFile\NonPhpFileProcessor;
use Rector\Core\PhpParser\Printer\BetterStandardPrinter;
use Rector\Testing\Contract\CommunityRectorTestCaseInterface;
use Rector\Testing\Guard\FixtureGuard;
use Rector\Testing\ValueObject\InputFilePathWithExpectedFile;
use Symplify\EasyTesting\DataProvider\StaticFixtureFinder;
use Symplify\EasyTesting\StaticFixtureSplitter;
use Symplify\PackageBuilder\Parameter\ParameterProvider;
use Symplify\PackageBuilder\Testing\AbstractKernelTestCase;
use Symplify\SmartFileSystem\SmartFileInfo;
use Symplify\SmartFileSystem\SmartFileSystem;

abstract class AbstractCommunityRectorTestCase extends AbstractKernelTestCase implements CommunityRectorTestCaseInterface
{
    /**
     * @var FileProcessor
     */
    protected $fileProcessor;

    /**
     * @var SmartFileSystem
     */
    protected $smartFileSystem;

    /**
     * @var NonPhpFileProcessor
     */
    protected $nonPhpFileProcessor;

    /**
     * @var ParameterProvider
     */
    protected $parameterProvider;

    /**
     * @var FixtureGuard
     */
    protected $fixtureGuard;

    /**
     * @var RemovedAndAddedFilesCollector
     */
    protected $removedAndAddedFilesCollector;

    /**
     * @var SmartFileInfo
     */
    protected $originalTempFileInfo;

    /**
     * @var SmartFileInfo
     */
    protected static $allRectorContainer;

    /**
     * @var BetterStandardPrinter
     */
    private $betterStandardPrinter;

    protected function setUp(): void
    {
        $this->smartFileSystem = new SmartFileSystem();
        $this->fixtureGuard = new FixtureGuard();

        $smartFileInfo = new SmartFileInfo($this->provideConfigFilePath());
        $configFileInfos = $this->resolveConfigs($smartFileInfo);

        $this->bootKernelWithConfigs(RectorKernel::class, $configFileInfos);

        $this->fileProcessor = $this->getService(FileProcessor::class);
        $this->nonPhpFileProcessor = $this->getService(NonPhpFileProcessor::class);
        $this->parameterProvider = $this->getService(ParameterProvider::class);
        $this->betterStandardPrinter = $this->getService(BetterStandardPrinter::class);
        $this->removedAndAddedFilesCollector = $this->getService(RemovedAndAddedFilesCollector::class);
        $this->removedAndAddedFilesCollector->reset();
    }

    protected function yieldFilesFromDirectory(string $directory, string $suffix = '*.php.inc'): Iterator
    {
        return StaticFixtureFinder::yieldDirectory($directory, $suffix);
    }

    /**
     * @param InputFilePathWithExpectedFile[] $extraFiles
     */
    protected function doTestFileInfo(SmartFileInfo $fixtureFileInfo, array $extraFiles = []): void
    {
        $this->fixtureGuard->ensureFileInfoHasDifferentBeforeAndAfterContent($fixtureFileInfo);

        $inputFileInfoAndExpectedFileInfo = StaticFixtureSplitter::splitFileInfoToLocalInputAndExpectedFileInfos(
            $fixtureFileInfo
        );

        $inputFileInfo = $inputFileInfoAndExpectedFileInfo->getInputFileInfo();

        // needed for PHPStan, because the analyzed file is just create in /temp
        /** @var NodeScopeResolver $nodeScopeResolver */
        $nodeScopeResolver = $this->getService(NodeScopeResolver::class);
        $nodeScopeResolver->setAnalysedFiles([$inputFileInfo->getRealPath()]);

        $expectedFileInfo = $inputFileInfoAndExpectedFileInfo->getExpectedFileInfo();

        $this->doTestFileMatchesExpectedContent($inputFileInfo, $expectedFileInfo, $fixtureFileInfo, $extraFiles);
        $this->originalTempFileInfo = $inputFileInfo;
    }

    protected function getTempPath(): string
    {
        return StaticFixtureSplitter::getTemporaryPath();
    }

    protected function doTestExtraFile(string $expectedExtraFileName, string $expectedExtraContentFilePath): void
    {
        $addedFilesWithContents = $this->removedAndAddedFilesCollector->getAddedFilesWithContent();
        foreach ($addedFilesWithContents as $addedFilesWithContent) {
            if (! Strings::endsWith($addedFilesWithContent->getFilePath(), $expectedExtraFileName)) {
                continue;
            }

            $this->assertStringEqualsFile($expectedExtraContentFilePath, $addedFilesWithContent->getFileContent());
            return;
        }

        $addedFilesWithNodes = $this->removedAndAddedFilesCollector->getAddedFilesWithNodes();
        foreach ($addedFilesWithNodes as $addedFileWithNodes) {
            if (! Strings::endsWith($addedFileWithNodes->getFilePath(), $expectedExtraFileName)) {
                continue;
            }

            $printedFileContent = $this->betterStandardPrinter->prettyPrintFile($addedFileWithNodes->getNodes());
            $this->assertStringEqualsFile($expectedExtraContentFilePath, $printedFileContent);
            return;
        }

        $movedFilesWithContent = $this->removedAndAddedFilesCollector->getMovedFileWithContent();
        foreach ($movedFilesWithContent as $movedFileWithContent) {
            if (! Strings::endsWith($movedFileWithContent->getNewPathname(), $expectedExtraFileName)) {
                continue;
            }

            $this->assertStringEqualsFile($expectedExtraContentFilePath, $movedFileWithContent->getFileContent());
            return;
        }

        throw new ShouldNotHappenException();
    }

    protected function getFixtureTempDirectory(): string
    {
        return sys_get_temp_dir() . '/_temp_fixture_rector_tests';
    }

    /**
     * @return SmartFileInfo[]
     */
    private function resolveConfigs(SmartFileInfo $configFileInfo): array
    {
        $configFileInfos = [$configFileInfo];

        $rectorConfigsResolver = new RectorConfigsResolver();
        $setFileInfos = $rectorConfigsResolver->resolveSetFileInfosFromConfigFileInfos($configFileInfos);

        return array_merge($configFileInfos, $setFileInfos);
    }

    /**
     * @param InputFilePathWithExpectedFile[] $extraFiles
     */
    private function doTestFileMatchesExpectedContent(
        SmartFileInfo $originalFileInfo,
        SmartFileInfo $expectedFileInfo,
        SmartFileInfo $fixtureFileInfo,
        array $extraFiles = []
    ): void {
        $this->parameterProvider->changeParameter(Option::SOURCE, [$originalFileInfo->getRealPath()]);

        if (! Strings::endsWith($originalFileInfo->getFilename(), '.blade.php') && in_array(
            $originalFileInfo->getSuffix(),
            ['php', 'phpt'],
            true
        )) {
            if ($extraFiles === []) {
                $this->fileProcessor->parseFileInfoToLocalCache($originalFileInfo);
                $this->fileProcessor->refactor($originalFileInfo);
                $this->fileProcessor->postFileRefactor($originalFileInfo);
            } else {
                $fileInfosToProcess = [$originalFileInfo];

                foreach ($extraFiles as $extraFile) {
                    $fileInfosToProcess[] = $extraFile->getInputFileInfo();
                }

                // life-cycle trio :)
                foreach ($fileInfosToProcess as $fileInfoToProcess) {
                    $this->fileProcessor->parseFileInfoToLocalCache($fileInfoToProcess);
                }

                foreach ($fileInfosToProcess as $fileInfoToProcess) {
                    $this->fileProcessor->refactor($fileInfoToProcess);
                }

                foreach ($fileInfosToProcess as $fileInfoToProcess) {
                    $this->fileProcessor->postFileRefactor($fileInfoToProcess);
                }
            }

            // mimic post-rectors
            $changedContent = $this->fileProcessor->printToString($originalFileInfo);
        } else {
            $message = sprintf('Suffix "%s" is not supported yet', $originalFileInfo->getSuffix());
            throw new ShouldNotHappenException($message);
        }

        $relativeFilePathFromCwd = $fixtureFileInfo->getRelativeFilePathFromCwd();
        $this->assertStringEqualsFile($expectedFileInfo->getRealPath(), $changedContent, $relativeFilePathFromCwd);
    }
}
