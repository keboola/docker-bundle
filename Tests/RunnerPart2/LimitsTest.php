<?php

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Generator;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Image\AWSElasticContainerRegistry;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Exception\ApplicationException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;

class LimitsTest extends TestCase
{
    public function testInstanceCpuLimitsInvalid()
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $limits = new Limits(
            $logger,
            ['cpu_count' => 'invalid'],
            [],
            [],
            [],
            null
        );
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            'cpu_count is set incorrectly in parameters.yml: This value should be a valid number.'
        );
        $limits->getCpuLimit($this->getImageMock());
    }

    public function testProjectCpuLimitsInvalid()
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $limits = new Limits(
            $logger,
            ['cpu_count' => 2],
            ['runner.cpuParallelism' => ['name' => 'runner.cpuParallelism', 'value' => 1000]],
            [],
            [],
            null
        );
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            'runner.cpuParallelism limit is set incorrectly: This value should be between 1 and 96.'
        );
        $limits->getCpuLimit($this->getImageMock());
    }

    public function testCpuLimitsInstance()
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $limits = new Limits(
            $logger,
            ['cpu_count' => 1],
            ['components.jobsParallelism' => ['name' => 'components.jobsParallelism', 'value' > 10]],
            ['foo', 'bar'],
            ['bar', 'kochba'],
            null
        );
        self::assertEquals(1, $limits->getCpuLimit($this->getImageMock()));
        self::assertStringContainsString('CPU limits - instance: 1 project: 2', $handler->getRecords()[0]['message']);
    }

    public function testCpuLimitsDefault()
    {
        $limits = new Limits(
            new NullLogger(),
            ['cpu_count' => 14],
            [],
            [],
            [],
            null
        );
        self::assertEquals(2, $limits->getCpuLimit($this->getImageMock()));
    }

    public function testCpuLimitsProject()
    {
        $limits = new Limits(
            new NullLogger(),
            ['cpu_count' => 14],
            ['runner.cpuParallelism' => ['name' => 'runner.cpuParallelism', 'value' => 10]],
            [],
            [],
            null
        );
        self::assertEquals(10, $limits->getCpuLimit($this->getImageMock()));
    }

    public function testCpuLimitsProjectInstance()
    {
        $limits = new Limits(
            new NullLogger(),
            ['cpu_count' => 2],
            ['runner.cpuParallelism' => ['name' => 'runner.cpuParallelism', 'value' > 10]],
            [],
            [],
            null
        );
        self::assertEquals(2, $limits->getCpuLimit($this->getImageMock()));
    }

    public function testProjectMemoryLimitsInvalid()
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $limits = new Limits(
            $logger,
            ['cpu_count' => 2],
            ['runner.keboola.r-transformation.memoryLimitMBs' =>
                ['name' => 'runner.keboola.r-transformation.memoryLimitMBs', 'value' => 120000000],
            ],
            [],
            [],
            null
        );
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            "'runner.keboola.r-transformation.memoryLimitMBs' limit is set incorrectly: " .
            'This value should be between 1 and 64000.'
        );
        $limits->getMemoryLimit($this->getImageMock('keboola.r-transformation'));
    }

    public function testProjectMemorySwapLimitsInvalid()
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $limits = new Limits(
            $logger,
            ['cpu_count' => 2],
            ['runner.keboola.r-transformation.memoryLimitMBs' =>
                ['name' => 'runner.keboola.r-transformation.memoryLimitMBs', 'value' => 120000000],
            ],
            [],
            [],
            null
        );
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            "'runner.keboola.r-transformation.memoryLimitMBs' limit is set incorrectly: " .
            'This value should be between 1 and 64000.'
        );
        $limits->getMemorySwapLimit($this->getImageMock('keboola.r-transformation'));
    }

    public function testProjectMemoryLimitsDefault()
    {
        $limits = new Limits(
            new NullLogger(),
            ['cpu_count' => 2],
            [],
            [],
            [],
            null
        );
        self::assertEquals('256m', $limits->getMemoryLimit($this->getImageMock('keboola.r-transformation')));
        self::assertEquals('256m', $limits->getMemorySwapLimit($this->getImageMock('keboola.r-transformation')));
    }

    public function testProjectMemoryLimitsOverride()
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $limits = new Limits(
            $logger,
            ['cpu_count' => 2],
            ['runner.keboola.r-transformation.memoryLimitMBs' =>
                ['name' => 'runner.keboola.r-transformation.memoryLimitMBs', 'value' => 60000],
            ],
            [],
            [],
            null
        );
        self::assertEquals('60000M', $limits->getMemoryLimit($this->getImageMock('keboola.r-transformation')));
        self::assertStringContainsString(
            "Memory limits - component: '256m' project: '60000M'",
            $handler->getRecords()[0]['message']
        );
        self::assertEquals('60000M', $limits->getMemorySwapLimit($this->getImageMock('keboola.r-transformation')));
    }

    public function testProjectMemoryLimitsNoEffect()
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $limits = new Limits(
            $logger,
            ['cpu_count' => 2],
            ['runner.keboola.r-transformation.memoryLimitMBs' =>
                ['name' => 'runner.keboola.r-transformation.memoryLimitMBs', 'value' => 60000],
            ],
            [],
            [],
            null
        );
        self::assertEquals('256m', $limits->getMemoryLimit($this->getImageMock('keboola.python-transformation')));
        self::assertStringContainsString("Memory limits - component: '256m' project: NULL", $handler->getRecords()[0]['message']);
        self::assertEquals('256m', $limits->getMemorySwapLimit($this->getImageMock('keboola.python-transformation')));
    }

    /**
     * @param string $id
     * @return Image
     */
    private function getImageMock($id = 'my-component')
    {
        $component = new Component([
            'id' => $id,
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => 'dummy',
                ],
            ],
        ]);
        $image = self::getMockBuilder(AWSElasticContainerRegistry::class)
            ->disableOriginalConstructor()
            ->setMethods(['getSourceComponent'])
            ->getMock();
        $image->method('getSourceComponent')
            ->will(self::returnValue($component));
        /** @var Image $image */
        return $image;
    }

    /**
     * @dataProvider dynamicBackendProvider
     */
    public function testDynamicBackend(
        ?string $containerType,
        string  $expectedMemoryLimit,
        string  $expectedCpuLimit
    ) {
        $component = new Component([
            'id' => 'keboola.runner-config-test',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => 'dummy',
                ],
                'memory' => '1g',
            ],
        ]);
        $image = $this->createMock(AWSElasticContainerRegistry::class);
        $image->method('getSourceComponent')->willReturn($component);

        $logger = new TestLogger();
        $limits = new Limits(
            $logger,
            ['cpu_count' => 2], // ignored
            ['runner.keboola.runner-config-test.memoryLimitMBs' => // ignored
                ['name' => 'runner.keboola.runner-config-test.memoryLimitMBs', 'value' => 60000],
            ],
            ['dynamic-backend-jobs'],
            [],
            $containerType
        );

        self::assertEquals($expectedMemoryLimit, $limits->getMemoryLimit($image));
        self::assertEquals($expectedMemoryLimit, $limits->getMemorySwapLimit($image));
        self::assertEquals($expectedCpuLimit, $limits->getCpuLimit($image));
        self::assertTrue($logger->hasNoticeThatContains(
            sprintf("Memory limits - component: '1g' project: '%s'", $expectedMemoryLimit)
        ));
        self::assertTrue($logger->hasNoticeThatContains(sprintf("CPU limit: %s", $expectedCpuLimit)));
    }

    public function dynamicBackendProvider(): Generator
    {
        yield 'no backend' => [
            'containerType' => null,
            'expectedMemoryLimit' => '500M',
            'expectedCpuLimit' => '1',
        ];
        yield 'xsmall backend' => [
            'containerType' => 'xsmall',
            'expectedMemoryLimit' => '500M',
            'expectedCpuLimit' => '1',
        ];
        yield 'small backend' => [
            'containerType' => 'small',
            'expectedMemoryLimit' => '1000M',
            'expectedCpuLimit' => '2',
        ];
        yield 'medium backend' => [
            'containerType' => 'medium',
            'expectedMemoryLimit' => '2000M',
            'expectedCpuLimit' => '4',
        ];
        yield 'large backend' => [
            'containerType' => 'large',
            'expectedMemoryLimit' => '7100M',
            'expectedCpuLimit' => '14',
        ];
        yield 'invalid backend' => [
            'containerType' => 'unknown',
            'expectedMemoryLimit' => '1000M',
            'expectedCpuLimit' => '1',
        ];
    }
}
