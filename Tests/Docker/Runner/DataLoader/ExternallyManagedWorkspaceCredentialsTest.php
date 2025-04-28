<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Runner\DataLoader;

use InvalidArgumentException;
use Keboola\DockerBundle\Docker\Runner\DataLoader\ExternallyManagedWorkspaceCredentials;
use Keboola\StagingProvider\Provider\Configuration\WorkspaceCredentials;
use PHPUnit\Framework\TestCase;

class ExternallyManagedWorkspaceCredentialsTest extends TestCase
{
    public static function provideArrayData(): iterable
    {
        yield 'snowflake with password' => [
            'data' => [
                'id' => '123',
                'type' => 'snowflake',
                '#password' => 'pass-value',
            ],
            'expectedResult' => new ExternallyManagedWorkspaceCredentials(
                id: '123',
                type: 'snowflake',
                password: 'pass-value',
                privateKey: null,
            ),
        ];

        yield 'snowflake with privateKey' => [
            'data' => [
                'id' => '123',
                'type' => 'snowflake',
                '#privateKey' => 'privateKey-value',
            ],
            'expectedResult' => new ExternallyManagedWorkspaceCredentials(
                id: '123',
                type: 'snowflake',
                password: null,
                privateKey: 'privateKey-value',
            ),
        ];
    }

    /** @dataProvider provideArrayData */
    public function testFromArray(array $data, ExternallyManagedWorkspaceCredentials $expectedResult): void
    {
        // @phpstan-ignore-next-line this test validates correct $data shape
        $result = ExternallyManagedWorkspaceCredentials::fromArray($data);
        self::assertEquals($expectedResult, $result);
    }

    public static function provideInvalidConstructArguments(): iterable
    {
        yield 'both password and privateKey' => [
            'password' => 'test',
            'privateKey' => 'test',
            'expectedError' => 'Exactly one of "privateKey" and "password" must be configured workspace_credentials',
        ];

        yield 'no password or privateKey' => [
            'password' => null,
            'privateKey' => null,
            'expectedError' => 'Exactly one of "privateKey" and "password" must be configured workspace_credentials',
        ];
    }

    /** @dataProvider provideInvalidConstructArguments */
    public function testConstructWithInvalidArguments(
        ?string $password,
        ?string $privateKey,
        string $expectedError,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedError);

        new ExternallyManagedWorkspaceCredentials(
            id: '123',
            type: 'snowflake',
            password: $password,
            privateKey: $privateKey,
        );
    }

    public static function provideGetCredentialsTestData(): iterable
    {
        yield 'snowflake with password' => [
            'id' => '123',
            'type' => 'snowflake',
            'password' => 'test',
            'privateKey' => null,
            'expectedResult' => new WorkspaceCredentials([
                'password' => 'test',
                'privateKey' => null,
            ]),
        ];

        yield 'snowflake with privateKey' => [
            'id' => '123',
            'type' => 'snowflake',
            'password' => null,
            'privateKey' => 'test',
            'expectedResult' => new WorkspaceCredentials([
                'password' => null,
                'privateKey' => 'test',
            ]),
        ];
    }

    /**
     * @param non-empty-string $id
     * @param "snowflake" $type
     * @dataProvider provideGetCredentialsTestData
     */
    public function testGetWorkspaceCredentials(
        string $id,
        string $type,
        ?string $password,
        ?string $privateKey,
        WorkspaceCredentials $expectedResult,
    ): void {
        $credentials = new ExternallyManagedWorkspaceCredentials(
            id: $id,
            type: $type,
            password: $password,
            privateKey: $privateKey,
        );

        self::assertEquals(
            $expectedResult,
            $credentials->getWorkspaceCredentials(),
        );
    }
}
