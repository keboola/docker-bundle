<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Configuration;

use Keboola\DockerBundle\Docker\Configuration;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class StateTest extends TestCase
{
    public function testEmptyState(): void
    {
        $state = [];
        $expected = [
            StateFile::NAMESPACE_COMPONENT => [],
        ];
        $processed = (new Configuration\State())->parse(['state' => $state]);
        self::assertEquals($expected, $processed);
    }

    public function testComponentState(): void
    {
        $state = [
            StateFile::NAMESPACE_COMPONENT => ['key' => 'foo'],
        ];
        $expected = [
            StateFile::NAMESPACE_COMPONENT => ['key' => 'foo'],
        ];
        $processed = (new Configuration\State())->parse(['state' => $state]);
        self::assertEquals($expected, $processed);
    }

    public function testStorageInputTablesState(): void
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [
                        [
                            'source' => 'sourceTable',
                            'lastImportDate' => 'someDate',
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            StateFile::NAMESPACE_COMPONENT => [],
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [
                        [
                            'source' => 'sourceTable',
                            'lastImportDate' => 'someDate',
                        ],
                    ],
                    StateFile::NAMESPACE_FILES => [],
                ],
            ],
        ];
        $processed = (new Configuration\State())->parse(['state' => $state]);
        self::assertEquals($expected, $processed);
    }

    public function testStorageInputTablesStateExtraKey(): void
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [
                        [
                            'source' => 'sourceTable',
                            'lastImportDate' => 'someDate',
                            'invalidKey' => 'invalidValue',
                        ],
                    ],
                ],
            ],
        ];

        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('Unrecognized option "invalidKey" under "state.storage.input.tables.0"');
        (new Configuration\State())->parse(['state' => $state]);
    }

    public function testStorageInputTablesStateMissingKey(): void
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [
                        [
                            'source' => 'sourceTable',
                        ],
                    ],
                ],
            ],
        ];

        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage(
            'The child config "lastImportDate" under "state.storage.input.tables.0" must be configured',
        );
        (new Configuration\State())->parse(['state' => $state]);
    }

    public function testStorageInputFilesState(): void
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_FILES => [
                        [
                            'tags' => [
                                [
                                    'name' => 'tag',
                                ],
                            ],
                            'lastImportId' => '12345',
                        ],
                    ],
                ],
            ],
        ];
        $expected = [
            StateFile::NAMESPACE_COMPONENT => [],
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [],
                    StateFile::NAMESPACE_FILES => [
                        [
                            'tags' => [
                                [
                                    'name' => 'tag',
                                ],
                            ],
                            'lastImportId' => '12345',
                        ],
                    ],
                ],
            ],
        ];
        $processed = (new Configuration\State())->parse(['state' => $state]);
        self::assertEquals($expected, $processed);
    }

    public function testStorageInputFilesStateExtraKey(): void
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_FILES => [
                        [
                            'tags' => [
                                [
                                    'name' => 'tag',
                                ],
                            ],
                            'lastImportId' => '12345',
                            'extraKey' => 'invalid',
                        ],
                    ],
                ],
            ],
        ];

        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('Unrecognized option "extraKey" under "state.storage.input.files.0"');
        (new Configuration\State())->parse(['state' => $state]);
    }

    public function testStorageInputFilesStateMissingKey(): void
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_FILES => [
                        [
                            'tags' => [
                                [
                                    'name' => 'tag',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage(
            'The child config "lastImportId" under "state.storage.input.files.0" must be configured',
        );
        (new Configuration\State())->parse(['state' => $state]);
    }

    public function testExtraRootKey(): void
    {
        $state = [
            'invalidKey' => 'invalidValue',
            'component' => [
                'key' => 'foo',
            ],
        ];

        (new Configuration\State())->parse(['state' => $state]);

        $processed = (new Configuration\State())->parse(['state' => $state]);
        self::assertEquals($state, $processed);
    }
}
