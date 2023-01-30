<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use PHPUnit\Framework\TestCase;

class OutputTest extends TestCase
{
    public function testAccessors()
    {
        $stateFileMock = $this->getMockBuilder(StateFile::class)->disableOriginalConstructor()->getMock();
        $images = [
            ['id' => 'apples', 'digests' => ['foo', 'baz']],
            ['id' => 'oranges', 'digests' => ['bar']],
        ];
        $output = new Output();
        $output->setImages($images);
        $output->setOutput('bazBar');
        $output->setConfigVersion('123');
        $output->setStateFile($stateFileMock);
        self::assertEquals('bazBar', $output->getProcessOutput());
        self::assertEquals(
            [
                0 => ['id' => 'apples', 'digests' => ['foo', 'baz']],
                1 => ['id' => 'oranges', 'digests' => ['bar']],
            ],
            $output->getImages()
        );
        self::assertEquals('123', $output->getConfigVersion());
        self::assertSame($stateFileMock, $output->getStateFile());
        self::assertNull($output->getInputFileStateList());
        self::assertNull($output->getInputTableResult());
        self::assertNull($output->getDataLoader());
        self::assertNull($output->getTableQueue());
        self::assertNull($output->getOutputTableResult());
        $output->setConfigVersion(null);
        self::assertNull($output->getConfigVersion());
    }
}
