<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Image\AWSElasticContainerRegistry;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\Syrup\Exception\ApplicationException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LimitsTest extends TestCase
{
    public function testInstanceLimitsInvalid()
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

    public function testProjectLimitsInvalid()
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

    public function testLimitsInstance()
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

    public function testLimitsDefault()
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

    public function testLimitsProject()
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

    public function testLimitsProjectInstance()
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

    public function testDeviceIOLimits()
    {
        $limits = new Limits(
            new NullLogger(),
            [],
            [],
            [],
            []
        );
        self::assertEquals("50m", $limits->getDeviceIOLimits($this->getImageMock()));
    }

    /**
     * @return Image
     */
    private function getImageMock()
    {
        $componentMock = self::getMockBuilder(Component::class)
            ->disableOriginalConstructor()
            ->getMock();
        $image = self::getMockBuilder(AWSElasticContainerRegistry::class)
            ->disableOriginalConstructor()
            ->setMethods(['getSourceComponent'])
            ->getMock();
        $image->method('getSourceComponent')
            ->will(self::returnValue($componentMock));
        /** @var Image $image */
        return $image;
    }
}
