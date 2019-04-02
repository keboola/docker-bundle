<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Image\AWSElasticContainerRegistry;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Exception\ApplicationException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Validator\Constraints\Null;

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
            []
        );
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('cpu_count is set incorrectly in parameters.yml: This value should be a valid number.');
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
            []
        );
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('runner.cpuParallelism limit is set incorrectly: This value should be 96 or less.');
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
            ['bar', 'kochba']
        );
        self::assertEquals(1, $limits->getCpuLimit($this->getImageMock()));
        self::assertContains('CPU limits - instance: 1 project: 2', $handler->getRecords()[0]['message']);
    }

    public function testCpuLimitsDefault()
    {
        $limits = new Limits(
            new NullLogger(),
            ['cpu_count' => 14],
            [],
            [],
            []
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
            []
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
            []
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
            []
        );
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            "'runner.keboola.r-transformation.memoryLimitMBs' limit is set incorrectly: " .
            "This value should be 64000 or less."
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
            []
        );
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage(
            "'runner.keboola.r-transformation.memoryLimitMBs' limit is set incorrectly: " .
            "This value should be 64000 or less."
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
            []
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
            []
        );
        self::assertEquals('60000M', $limits->getMemoryLimit($this->getImageMock('keboola.r-transformation')));
        self::assertContains(
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
                ['name' => 'runner.keboola.r-transformation.memoryLimitMBs', 'value' => 60000]
            ],
            [],
            []
        );
        self::assertEquals('256m', $limits->getMemoryLimit($this->getImageMock('keboola.python-transformation')));
        self::assertContains("Memory limits - component: '256m' project: NULL", $handler->getRecords()[0]['message']);
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
}
