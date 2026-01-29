<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Image\ReplicatedRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;

class ReplicatedRegistryTest extends TestCase
{
    /** @dataProvider isEnabledDataProvider */
    public function testIsEnabled(bool $useReplicatedRegistry, string $url, bool $expectedEnabled): void
    {
        $service = new ReplicatedRegistry(
            $useReplicatedRegistry,
            $url,
            'testuser',
            'testpass',
        );

        self::assertSame($expectedEnabled, $service->isEnabled());
    }

    public static function isEnabledDataProvider(): iterable
    {
        yield 'disabled with valid URL' => [
            'useReplicatedRegistry' => false,
            'url' => 'registry.example.com/keboola',
            'expectedEnabled' => false,
        ];
        yield 'enabled with empty URL' => [
            'useReplicatedRegistry' => true,
            'url' => '',
            'expectedEnabled' => false,
        ];
        yield 'enabled with valid URL' => [
            'useReplicatedRegistry' => true,
            'url' => 'registry.example.com/keboola',
            'expectedEnabled' => true,
        ];
        yield 'disabled with empty URL' => [
            'useReplicatedRegistry' => false,
            'url' => '',
            'expectedEnabled' => false,
        ];
        yield 'enabled with URL with port' => [
            'useReplicatedRegistry' => true,
            'url' => 'registry.example.com:5000/keboola',
            'expectedEnabled' => true,
        ];
        yield 'enabled with URL with multiple paths' => [
            'useReplicatedRegistry' => true,
            'url' => 'registry.example.com/org/team/keboola',
            'expectedEnabled' => true,
        ];
    }

    /** @dataProvider transformImageUrlDataProvider */
    public function testTransformImageUrl(
        string $replicatedRegistryUrl,
        string $originalUrl,
        string $expectedUrl,
    ): void {
        $service = new ReplicatedRegistry(
            true,
            $replicatedRegistryUrl,
            'testuser',
            'testpass',
        );

        self::assertSame($expectedUrl, $service->transformImageUrl($originalUrl));
    }

    public static function transformImageUrlDataProvider(): iterable
    {
        yield 'ECR to GAR with tag' => [
            'replicatedRegistryUrl' => 'registry.example.com/keboola',
            'originalUrl' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/component:1.0.0',
            'expectedUrl' => 'registry.example.com/keboola/developer-portal-v2/component:1.0.0',
        ];
        yield 'ECR to GAR without tag' => [
            'replicatedRegistryUrl' => 'registry.example.com/keboola',
            'originalUrl' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/component',
            'expectedUrl' => 'registry.example.com/keboola/developer-portal-v2/component',
        ];
        yield 'ECR to GAR with digest' => [
            'replicatedRegistryUrl' => 'registry.example.com/keboola',
            'originalUrl' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/component@sha256:abcd1234',
            'expectedUrl' => 'registry.example.com/keboola/component@sha256:abcd1234',
        ];
        yield 'non-ECR image unchanged' => [
            'replicatedRegistryUrl' => 'registry.example.com/keboola',
            'originalUrl' => 'docker.io/library/nginx:latest',
            'expectedUrl' => 'docker.io/library/nginx:latest',
        ];
        yield 'ECR to ACR' => [
            'replicatedRegistryUrl' => 'myregistry.azurecr.io',
            'originalUrl' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/component:v1',
            'expectedUrl' => 'myregistry.azurecr.io/component:v1',
        ];
        yield 'ECR to GAR with port' => [
            'replicatedRegistryUrl' => 'registry.example.com:5000/keboola',
            'originalUrl' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/component:latest',
            'expectedUrl' => 'registry.example.com:5000/keboola/component:latest',
        ];
        yield 'ECR to GAR with multiple path segments' => [
            'replicatedRegistryUrl' => 'registry.example.com/org/team/keboola',
            'originalUrl' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/component:v1.0.0',
            'expectedUrl' => 'registry.example.com/org/team/keboola/component:v1.0.0',
        ];
    }

    public function testTransformImageUrlWhenDisabledThrowsException(): void
    {
        $service = new ReplicatedRegistry(
            false,
            '',
            'testuser',
            'testpass',
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Replicated registry is not enabled');

        $service->transformImageUrl('147946154733.dkr.ecr.us-east-1.amazonaws.com/component:v1');
    }

    /** @dataProvider loginParamsDataProvider */
    public function testGetLoginParams(
        string $registryUrl,
        string $username,
        string $password,
        string $expectedParams,
    ): void {
        $service = new ReplicatedRegistry(
            true,
            $registryUrl,
            $username,
            $password,
        );

        self::assertSame($expectedParams, $service->getLoginParams());
    }

    public static function loginParamsDataProvider(): iterable
    {
        yield 'simple credentials' => [
            'registryUrl' => 'registry.example.com/keboola',
            'username' => 'testuser',
            'password' => 'testpass',
            'expectedParams' => "--username='testuser' --password='testpass' 'registry.example.com'",
        ];
        yield 'username with special characters' => [
            'registryUrl' => 'registry.example.com/keboola',
            'username' => 'test$user',
            'password' => 'testpass',
            'expectedParams' => "--username='test\$user' --password='testpass' 'registry.example.com'",
        ];
        yield 'password with quotes' => [
            'registryUrl' => 'registry.example.com/keboola',
            'username' => 'testuser',
            'password' => 'test"pass\'word',
            'expectedParams' => "--username='testuser' --password='test\"pass'\\''word' 'registry.example.com'",
        ];
        yield 'registry with port' => [
            'registryUrl' => 'registry.example.com:5000/keboola',
            'username' => 'testuser',
            'password' => 'testpass',
            'expectedParams' => "--username='testuser' --password='testpass' 'registry.example.com:5000'",
        ];
        yield 'registry with multiple paths' => [
            'registryUrl' => 'registry.example.com/org/team/keboola',
            'username' => 'testuser',
            'password' => 'testpass',
            'expectedParams' => "--username='testuser' --password='testpass' 'registry.example.com'",
        ];
        yield 'empty credentials' => [
            'registryUrl' => 'registry.example.com/keboola',
            'username' => '',
            'password' => '',
            'expectedParams' => "--username='' --password='' 'registry.example.com'",
        ];
        yield 'username with spaces' => [
            'registryUrl' => 'registry.example.com/keboola',
            'username' => 'test user',
            'password' => 'testpass',
            'expectedParams' => "--username='test user' --password='testpass' 'registry.example.com'",
        ];
        yield 'password with backslash' => [
            'registryUrl' => 'registry.example.com/keboola',
            'username' => 'testuser',
            'password' => 'test\\pass',
            'expectedParams' => "--username='testuser' --password='test\\pass' 'registry.example.com'",
        ];
    }

    /** @dataProvider logoutParamsDataProvider */
    public function testGetLogoutParams(string $registryUrl, string $expectedParams): void
    {
        $service = new ReplicatedRegistry(
            true,
            $registryUrl,
            'testuser',
            'testpass',
        );

        self::assertSame($expectedParams, $service->getLogoutParams());
    }

    public static function logoutParamsDataProvider(): iterable
    {
        yield 'simple registry' => [
            'registryUrl' => 'registry.example.com/keboola',
            'expectedParams' => "'registry.example.com'",
        ];
        yield 'registry with port' => [
            'registryUrl' => 'registry.example.com:5000/keboola',
            'expectedParams' => "'registry.example.com:5000'",
        ];
        yield 'registry with multiple paths' => [
            'registryUrl' => 'registry.example.com/org/team/keboola',
            'expectedParams' => "'registry.example.com'",
        ];
        yield 'registry without path' => [
            'registryUrl' => 'registry.example.com',
            'expectedParams' => "'registry.example.com'",
        ];
        yield 'localhost registry' => [
            'registryUrl' => 'localhost:5000/keboola',
            'expectedParams' => "'localhost:5000'",
        ];
    }
}
