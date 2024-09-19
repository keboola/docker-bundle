<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Runner\DataLoader;

use Generator;
use Keboola\DockerBundle\Docker\Runner\DataLoader\ExternallyManagedWorkspaceCredentials;
use Keboola\DockerBundle\Exception\ExternalWorkspaceException;
use PHPUnit\Framework\TestCase;

class ExternallyManagedWorkspaceCredentialsTest extends TestCase
{
    public function testFromArray(): void
    {
        $dataArray = [
            'id' => '1234',
            'type' => 'snowflake',
            '#password' => 'test',
        ];
        $credentials = ExternallyManagedWorkspaceCredentials::fromArray($dataArray);
        self::assertSame('1234', $credentials->id);
        self::assertSame('snowflake', $credentials->type);
        self::assertSame('test', $credentials->password);
    }

    public function testDatabaseCredentials(): void
    {
        $dataArray = [
            'id' => '1234',
            'type' => 'snowflake',
            '#password' => 'test',
        ];
        $credentials = ExternallyManagedWorkspaceCredentials::fromArray($dataArray);
        $dbCredentials = $credentials->getDatabaseCredentials();
        self::assertSame(['password' => 'test'], $dbCredentials->toArray());
    }

    /**
     * @dataProvider invalidArrayProvider
     */
    public function testFromInvalidArray(array $dataArray, string $expectedMessage): void
    {
        $this->expectExceptionMessage($expectedMessage);
        $this->expectException(ExternalWorkspaceException::class);
        ExternallyManagedWorkspaceCredentials::fromArray($dataArray);
    }

    public function invalidArrayProvider(): Generator
    {
        yield 'missing id' => [
            'dataArray' => [
                'type' => 'snowflake',
                '#password' => 'test',
            ],
            'expectedMessage' => 'Missing required fields (id, type, #password) in workspace_credentials',
        ];
        yield 'missing type' => [
            'dataArray' => [
                'id' => '1234',
                '#password' => 'test',
            ],
            'expectedMessage' => 'Missing required fields (id, type, #password) in workspace_credentials',
        ];
        yield 'missing password' => [
            'dataArray' => [
                'id' => '1234',
                'type' => 'snowflake',
            ],
            'expectedMessage' => 'Missing required fields (id, type, #password) in workspace_credentials',
        ];
        yield 'unsupported type' => [
            'dataArray' => [
                'id' => '1234',
                'type' => 'unsupported',
                '#password' => 'test',
            ],
            'expectedMessage' => 'Unsupported workspace type "unsupported"',
        ];
    }
}
