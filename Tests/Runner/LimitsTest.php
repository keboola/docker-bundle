<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Image\AWSElasticContainerRegistry;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\Syrup\Exception\ApplicationException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;

class LimitsTest extends \PHPUnit_Framework_TestCase
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
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('cpu_count is set incorrectly in parameters.yml: This value should be a valid number.');
        $limits->getCpuLimit($image);
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
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('runner.cpuParallelism limit is set incorrectly: This value should be 96 or less.');
        $limits->getCpuLimit($image);
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
        self::assertEquals(1, $limits->getCpuLimit($image));
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
        self::assertEquals(2, $limits->getCpuLimit($image));
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
        self::assertEquals(10, $limits->getCpuLimit($image));
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
        self::assertEquals(2, $limits->getCpuLimit($image));
    }
}
