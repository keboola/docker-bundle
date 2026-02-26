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

    /** @dataProvider composeImageUrlDataProvider */
    public function testComposeImageUrl(
        string $replicatedRegistryUrl,
        string $definitionName,
        string $expectedUrl,
    ): void {
        $service = new ReplicatedRegistry(
            true,
            $replicatedRegistryUrl,
            'testuser',
            'testpass',
        );

        self::assertSame($expectedUrl, $service->composeImageUrl($definitionName));
    }

    public static function composeImageUrlDataProvider(): iterable
    {
        yield 'replicated URL when defined' => [
            'replicatedRegistryUrl' => 'registry.example.com/keboola',
            'definitionName' => 'developer-portal-v2/component',
            'expectedUrl' => 'registry.example.com/keboola/developer-portal-v2/component',
        ];
        yield 'replicated URL with trailing slash is normalised' => [
            'replicatedRegistryUrl' => 'registry.example.com/keboola/',
            'definitionName' => 'component',
            'expectedUrl' => 'registry.example.com/keboola/component',
        ];
        yield 'definition name with excess slashes is normalised' => [
            'replicatedRegistryUrl' => 'registry.example.com/keboola',
            'definitionName' => '/component/name/',
            'expectedUrl' => 'registry.example.com/keboola/component/name',
        ];
    }

    /** @dataProvider composeImageUrlWhenDisabledDataProvider */
    public function testComposeImageUrlWhenDisabledThrowsException(
        bool $useReplicatedRegistry,
        string $replicatedRegistryUrl,
    ): void {
        $service = new ReplicatedRegistry($useReplicatedRegistry, $replicatedRegistryUrl, 'testuser', 'testpass');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Replicated registry is not enabled');

        $service->composeImageUrl('keboola/component');
    }

    public static function composeImageUrlWhenDisabledDataProvider(): iterable
    {
        yield 'disabled with empty URL' => [
            'useReplicatedRegistry' => false,
            'replicatedRegistryUrl' => '',
        ];
        yield 'disabled with non-empty URL' => [
            'useReplicatedRegistry' => false,
            'replicatedRegistryUrl' => 'registry.example.com/keboola',
        ];
        yield 'enabled with empty URL' => [
            'useReplicatedRegistry' => true,
            'replicatedRegistryUrl' => '',
        ];
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
