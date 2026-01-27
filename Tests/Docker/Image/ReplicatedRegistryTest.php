<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Image\ReplicatedRegistry;
use PHPUnit\Framework\TestCase;

class ReplicatedRegistryTest extends TestCase
{
    public function testIsEnabledReturnsFalseWhenNotEnabled(): void
    {
        $service = new ReplicatedRegistry(
            false,
            'registry.example.com/keboola',
            'testuser',
            'testpass',
        );

        self::assertFalse($service->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenUrlEmpty(): void
    {
        $service = new ReplicatedRegistry(
            true,
            '',
            'testuser',
            'testpass',
        );

        self::assertFalse($service->isEnabled());
    }

    public function testIsEnabledReturnsTrueWhenEnabled(): void
    {
        $service = new ReplicatedRegistry(
            true,
            'registry.example.com/keboola',
            'testuser',
            'testpass',
        );

        self::assertTrue($service->isEnabled());
    }


    public function testTransformImageUrl(): void
    {
        $service = new ReplicatedRegistry(
            true,
            'registry.example.com/keboola',
            'testuser',
            'testpass',
        );

        $originalUrl = '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/component:1.0.0';
        $expectedUrl = 'registry.example.com/keboola/developer-portal-v2/component:1.0.0';

        self::assertSame($expectedUrl, $service->transformImageUrl($originalUrl));
    }

    public function testTransformImageUrlWithoutMatch(): void
    {
        $service = new ReplicatedRegistry(
            true,
            'registry.example.com/keboola',
            'testuser',
            'testpass',
        );

        $originalUrl = 'docker.io/library/nginx:latest';

        self::assertSame($originalUrl, $service->transformImageUrl($originalUrl));
    }

    public function testGetLoginParams(): void
    {
        $service = new ReplicatedRegistry(
            true,
            'registry.example.com/keboola',
            'testuser',
            'testpass',
        );

        $loginParams = $service->getLoginParams();

        self::assertSame("--username='testuser' --password='testpass' 'registry.example.com'", $loginParams);
    }

    public function testGetLoginParamsEscapesSpecialCharacters(): void
    {
        $service = new ReplicatedRegistry(
            true,
            'registry.example.com/keboola',
            'test$user',
            'test"pass\'word',
        );

        $loginParams = $service->getLoginParams();

        self::assertSame(
            "--username='test\$user' --password='test\"pass'\\''word' 'registry.example.com'",
            $loginParams,
        );
    }

    public function testGetLogoutParams(): void
    {
        $service = new ReplicatedRegistry(
            true,
            'registry.example.com/keboola',
            'testuser',
            'testpass',
        );

        $logoutParams = $service->getLogoutParams();

        self::assertSame("'registry.example.com'", $logoutParams);
    }
}
