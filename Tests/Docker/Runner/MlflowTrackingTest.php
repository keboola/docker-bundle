<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Runner;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\MlflowTracking;
use PHPUnit\Framework\TestCase;

class MlflowTrackingTest extends TestCase
{
    public function testBasicTracking(): void
    {
        $outputFilter = new OutputFilter(10000);
        $tracking = new MlflowTracking('uri');

        self::assertSame('uri', $tracking->getUri());
        self::assertNull($tracking->getToken());

        self::assertSame([
            'MLFLOW_TRACKING_URI' => 'uri',
        ], $tracking->exportAsEnv($outputFilter));
    }

    public function testTrackingWithToken(): void
    {
        $outputFilter = new OutputFilter(10000);
        $tracking = new MlflowTracking('uri', 'token');

        self::assertSame('uri', $tracking->getUri());
        self::assertSame('token', $tracking->getToken());

        self::assertSame([
            'MLFLOW_TRACKING_URI' => 'uri',
            'MLFLOW_TRACKING_TOKEN' => 'token',
        ], $tracking->exportAsEnv($outputFilter));

        self::assertSame('foo [hidden] bar', $outputFilter->filter('foo token bar'));
    }
}
