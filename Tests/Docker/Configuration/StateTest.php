<?php

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class StateTest extends TestCase
{
    public function testEmptyState()
    {
        $state = [];
        $expected = [
            StateFile::NAMESPACE_COMPONENT => []
        ];
        $processed = (new Configuration\State())->parse(["state" => $state]);
        self::assertEquals($expected, $processed);
    }

    public function testComponentState()
    {
        $state = [
            StateFile::NAMESPACE_COMPONENT => ["key" => "foo"]
        ];
        $expected = [
            StateFile::NAMESPACE_COMPONENT => ["key" => "foo"]
        ];
        $processed = (new Configuration\State())->parse(["state" => $state]);
        self::assertEquals($expected, $processed);
    }

    public function testStorageInputTablesState()
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [
                        "source" => "sourceTable",
                        "lastImportDate" => "someDate"
                    ]
                ]
            ]
        ];
        $expected = [
            StateFile::NAMESPACE_COMPONENT => [],
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [
                        "source" => "sourceTable",
                        "lastImportDate" => "someDate"
                    ]
                ]
            ]
        ];
        $processed = (new Configuration\State())->parse(["state" => $state]);
        self::assertEquals($expected, $processed);
    }

    public function testInvalidStorageInputTablesState()
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [
                        "source" => "sourceTable",
                        "lastImportDate" => "someDate",
                        "invalidKey" => "invalidValue"
                    ]
                ]
            ]
        ];

        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('Unrecognized option "invalidKey" under "state.storage.input.tables"');
        (new Configuration\State())->parse(["state" => $state]);
    }
    
    public function testInvalidRootKey()
    {
        $state = [
            "invalidKey" => "invalidValue"
        ];

        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('Unrecognized option "invalidKey" under "state"');
        (new Configuration\State())->parse(["state" => $state]);
    }
}
