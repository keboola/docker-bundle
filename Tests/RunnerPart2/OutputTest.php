<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\Artifacts\Result as ArtifactsResult;
use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoaderInterface;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\Table\Result as InputMappingTableResult;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\OutputMapping\Table\Result as OutputMappingTableResult;
use Keboola\StorageApiBranch\ClientWrapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OutputTest extends TestCase
{
    public function testDefaults(): void
    {
        $output = new Output();

        self::assertSame([], $output->getImages());
        // self::assertNull($output->getProcessOutput()); // does not have default value
        self::assertNull($output->getConfigVersion());
        self::assertNull($output->getTableQueue());
        self::assertNull($output->getInputTableResult());
        self::assertNull($output->getInputFileStateList());
        self::assertNull($output->getStagingWorkspace());
        // self::assertNull($output->getStateFile()); // does not have default value
        self::assertSame([], $output->getArtifactsUploaded());
        self::assertSame([], $output->getArtifactsDownloaded());
        self::assertNull($output->getOutputTableResult());
        self::assertSame([], $output->getInputVariableValues());
    }

    public function testSettersAndGetters(): void
    {
        $output = new Output();

        $images = [
            ['id' => 'apples', 'digests' => ['foo', 'baz']],
            ['id' => 'oranges', 'digests' => ['bar']],
        ];
        $output->setImages($images);
        self::assertSame($images, $output->getImages());

        $output->setOutput('bazBar');
        self::assertSame('bazBar', $output->getProcessOutput());

        $output->setConfigVersion('123');
        self::assertEquals('123', $output->getConfigVersion());

        $tableQueue = new LoadTableQueue(
            $this->createMock(ClientWrapper::class),
            $this->createMock(LoggerInterface::class),
            [],
        );
        $output->setTableQueue($tableQueue);
        self::assertSame($tableQueue, $output->getTableQueue());

        $inputResult = new InputMappingTableResult();
        $output->setInputTableResult($inputResult);
        self::assertSame($inputResult, $output->getInputTableResult());

        $inputFileStateList = new InputFileStateList([]);
        $output->setInputFileStateList($inputFileStateList);
        self::assertSame($inputFileStateList, $output->getInputFileStateList());

        $dataLoader = $this->createMock(DataLoaderInterface::class);
        $output->setStagingWorkspace($dataLoader);
        self::assertSame($dataLoader, $output->getStagingWorkspace());

        $stateFileMock = $this->createMock(StateFile::class);
        $output->setStateFile($stateFileMock);
        self::assertSame($stateFileMock, $output->getStateFile());

        $inputArtifactsResult = [new ArtifactsResult(123, false)];
        $output->setArtifactsDownloaded($inputArtifactsResult);
        self::assertSame($inputArtifactsResult, $output->getArtifactsDownloaded());

        $outputArtifactsResult = [new ArtifactsResult(456, true)];
        $output->setArtifactsUploaded($outputArtifactsResult);
        self::assertSame($outputArtifactsResult, $output->getArtifactsUploaded());

        $outputResult = new OutputMappingTableResult();
        $output->setOutputTableResult($outputResult);
        self::assertSame($outputResult, $output->getOutputTableResult());

        $output->setInputVariableValues(['foo' => 'bar']);
        self::assertSame(['foo' => 'bar'], $output->getInputVariableValues());
    }
}
