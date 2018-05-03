<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Image\AWSElasticContainerRegistry;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Psr\Log\NullLogger;

class LimitsTest extends \PHPUnit_Framework_TestCase
{
    public function testLimits()
    {
        $limits = new Limits(
            new NullLogger(),
            ['cpu_count' => 2],
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
        self::assertEquals(2, $limits->getCpuLimit($image));
    }
}
