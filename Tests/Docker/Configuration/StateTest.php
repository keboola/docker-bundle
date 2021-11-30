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
        // symfony key normalization https://symfony.com/doc/4.4/components/config/definition.html#normalization
        $state = [
            StateFile::NAMESPACE_COMPONENT => [
                'first-component' => [
                    'sample-data' => 1,
                ],
                'second_component' => [
                    'sample_data' => 2,
                ],
                'third-other_component' => [
                    'sample_da-ta' => 3,
                ],
            ],
        ];
        $expected = [
            StateFile::NAMESPACE_COMPONENT => [
                'first_component' => [
                    'sample-data' => 1,
                ],
                'second_component' => [
                    'sample_data' => 2,
                ],
                'third-other_component' => [
                    'sample_da-ta' => 3,
                ],
            ],
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
                        [
                            "source" => "sourceTable",
                            "lastImportDate" => "someDate"
                        ]
                    ]
                ]
            ]
        ];
        $expected = [
            StateFile::NAMESPACE_COMPONENT => [],
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [
                        [
                            "source" => "sourceTable",
                            "lastImportDate" => "someDate"
                        ]
                    ],
                    StateFile::NAMESPACE_FILES => [],
                ]
            ]
        ];
        $processed = (new Configuration\State())->parse(["state" => $state]);
        self::assertEquals($expected, $processed);
    }

    public function testStorageInputTablesStateExtraKey()
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [
                        [
                            "source" => "sourceTable",
                            "lastImportDate" => "someDate",
                            "invalidKey" => "invalidValue"
                        ]
                    ]
                ]
            ]
        ];

        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('Unrecognized option "invalidKey" under "state.storage.input.tables.0"');
        (new Configuration\State())->parse(["state" => $state]);
    }

    public function testStorageInputTablesStateMissingKey()
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_TABLES => [
                        [
                            "source" => "sourceTable"
                        ]
                    ]
                ]
            ]
        ];

        self::expectException(InvalidConfigurationException::class);
        self::expectExceptionMessage('The child node "lastImportDate" at path "state.storage.input.tables.0" must be configured');
        (new Configuration\State())->parse(["state" => $state]);
    }

    public function testStorageInputFilesState()
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
                        ]
                    ]
                ]
            ]
        ];
        $processed = (new Configuration\State())->parse(['state' => $state]);
        self::assertEquals($expected, $processed);
    }

    public function testStorageInputFilesStateExtraKey()
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

    public function testStorageInputFilesStateMissingKey()
    {
        $state = [
            StateFile::NAMESPACE_STORAGE => [
                StateFile::NAMESPACE_INPUT => [
                    StateFile::NAMESPACE_FILES => [
                        [
                            "tags" => [
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
        self::expectExceptionMessage('The child node "lastImportId" at path "state.storage.input.files.0" must be configured');
        (new Configuration\State())->parse(['state' => $state]);
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
