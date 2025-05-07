<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner;

use Generator;
use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\DockerBundle\Docker\OutputFilter\NullFilter;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\DockerBundle\Tests\TestEnvVarsTrait;
use Keboola\InputMapping\State\InputFileStateList;
use Keboola\InputMapping\State\InputTableStateList;
use Keboola\ObjectEncryptor\EncryptorOptions;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Component\Filesystem\Filesystem;

class StateFileTest extends TestCase
{
    use TestEnvVarsTrait;

    private string $dataDir;
    private ClientWrapper $clientWrapper;

    public function setUp(): void
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . self::getOptionalEnv('AWS_ECR_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . self::getOptionalEnv('AWS_ECR_SECRET_ACCESS_KEY'));

        // Create folders
        $temp = new Temp('docker');
        $fs = new Filesystem();
        $this->dataDir = $temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'data';
        $fs->mkdir($this->dataDir);
        $fs->mkdir($this->dataDir . DIRECTORY_SEPARATOR . 'in');
        $fs->mkdir($this->dataDir . DIRECTORY_SEPARATOR . 'out');

        $this->clientWrapper = new ClientWrapper(new ClientOptions(
            self::getRequiredEnv('STORAGE_API_URL'),
            self::getRequiredEnv('STORAGE_API_TOKEN'),
        ));
    }

    /**
     * @param ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT $branchType
     */
    private function getJobScopedEncryptor(
        string $branchType = 'default',
        array $features = [],
    ): JobScopedEncryptor {
        $stackId = parse_url(self::getRequiredEnv('STORAGE_API_URL'), PHP_URL_HOST);
        self::assertNotEmpty($stackId);
        return new JobScopedEncryptor(
            ObjectEncryptorFactory::getEncryptor(new EncryptorOptions(
                $stackId,
                self::getRequiredEnv('AWS_KMS_TEST_KEY'),
                self::getRequiredEnv('AWS_ECR_REGISTRY_REGION'),
                null,
                null,
            )),
            'my-component',
            'project-id',
            'config-id',
            $branchType,
            $features,
        );
    }

    public function testCreateStateFileFromStateWithNamespace(): void
    {
        $stateFile = new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->getJobScopedEncryptor(),
            [
                StateFile::NAMESPACE_COMPONENT => ['lastUpdate' => 'today'],
            ],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        $stateFile->createStateFile();
        $fileName = $this->dataDir . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'state.json';
        self::assertTrue(file_exists($fileName));
        $obj = new stdClass();
        $obj->lastUpdate = 'today';
        self::assertEquals(
            $obj,
            json_decode((string) file_get_contents($fileName), false),
        );
    }

    public function testCreateStateFileWithEmptyState(): void
    {
        $stateFile = new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->getJobScopedEncryptor(),
            [],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        $stateFile->createStateFile();
        $fileName = $this->dataDir . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'state.json';
        self::assertTrue(file_exists($fileName));
        self::assertEquals(
            new stdClass(),
            json_decode((string) file_get_contents($fileName), false),
        );
    }

    public function testPersistStateWithoutStateChange(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                self::equalTo('branch/default/components/my-component/configs/config-id/state'),
                self::equalTo(
                    ['state' => [
                        StateFile::NAMESPACE_COMPONENT => [
                            'key' => 'fooBar',
                        ],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ]],
                ),
            );

        $state = ['key' => 'fooBar'];

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);
        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => $state],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            $logger,
        );
        $stateFile->stashState($state);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }


    public function testPersistStateLogsSavingState(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson');

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            $logger,
        );
        $stateFile->stashState(['key' => 'fooBar', 'foo' => 'bar']);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
        self::assertTrue($logsHandler->hasRecord('Storing state: {"component":{"key":"fooBar","foo":"bar"},"storage":{"input":{"tables":[],"files":[]}}}', Level::Notice));
    }

    /**
     * @dataProvider stateEncryptionOptionsProvider
     * @param ObjectEncryptor::BRANCH_TYPE_DEV|ObjectEncryptor::BRANCH_TYPE_DEFAULT $branchType
     */
    public function testPersistStateEncrypts(array $features, string $branchType, string $expectedPrefix): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                $this->equalTo('branch/default/components/my-component/configs/config-id/state'),
                $this->callback(function ($argument) use ($expectedPrefix) {
                    self::assertArrayHasKey('state', $argument);
                    $data = $argument['state'];
                    self::assertArrayHasKey('key', $data[StateFile::NAMESPACE_COMPONENT]);
                    self::assertEquals('fooBar', $data[StateFile::NAMESPACE_COMPONENT]['key']);
                    self::assertArrayHasKey('#foo', $data[StateFile::NAMESPACE_COMPONENT]);
                    self::assertStringStartsWith($expectedPrefix, $data[StateFile::NAMESPACE_COMPONENT]['#foo']);
                    return true;
                }),
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor($branchType, $features),
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        $stateFile->stashState(['key' => 'fooBar', '#foo' => 'bar']);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function stateEncryptionOptionsProvider(): Generator
    {
        yield 'default branch no feature' => [
            'features' => [],
            'branchType' => 'default',
            'expectedPrefix' => 'KBC::ProjectSecure::',
        ];
        yield 'default branch with feature' => [
            'features' => ['protected-default-branch'],
            'branchType' => 'default',
            'expectedPrefix' => 'KBC::BranchTypeSecure::',
        ];
        yield 'non-default branch no feature' => [
            'features' => [],
            'branchType' => 'dev',
            'expectedPrefix' => 'KBC::ProjectSecure::',
        ];
        yield 'non-default branch with feature' => [
            'features' => ['protected-default-branch'],
            'branchType' => 'dev',
            'expectedPrefix' => 'KBC::BranchTypeSecure::',
        ];
    }

    public function testStashStateDoesNotUpdate(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::never())
            ->method('apiPut');
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        $stateFile->stashState(['key' => 'fooBar', '#foo' => 'bar']);
    }

    public function testPersistsStateUpdatesFromEmpty(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                self::equalTo('branch/default/components/my-component/configs/config-id/state'),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = $argument['state'];
                    self::assertArrayHasKey(StateFile::NAMESPACE_COMPONENT, $data);
                    self::assertArrayHasKey('key', $data[StateFile::NAMESPACE_COMPONENT]);
                    self::assertEquals('fooBar', $data[StateFile::NAMESPACE_COMPONENT]['key']);
                    return true;
                }),
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        $stateFile->stashState(['key' => 'fooBar']);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testPersistsStateUpdatesToEmptyArray(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                self::equalTo('branch/default/components/my-component/configs/config-id/state'),
                self::equalTo(
                    ['state' => [
                        StateFile::NAMESPACE_COMPONENT => [],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ]],
                ),
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBar']],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        $stateFile->stashState([]);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testPersistsStateUpdatesToEmptyObject(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                self::equalTo('branch/default/components/my-component/configs/config-id/state'),
                self::equalTo(
                    ['state' => [
                        StateFile::NAMESPACE_COMPONENT => new stdClass(),
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ]],
                ),
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBar']],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        $stateFile->stashState(new stdClass());
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testPersistsStateSavesUnchangedState(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                self::equalTo('branch/default/components/my-component/configs/config-id/state'),
                self::equalTo(
                    ['state' => [
                        StateFile::NAMESPACE_COMPONENT => [
                            'key' => 'fooBar',
                        ],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ]],
                ),
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBar']],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        $stateFile->stashState(['key' => 'fooBar']);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testLoadStateFromFile(): void
    {
        $fs = new Filesystem();
        $data = ['time' => ['previousStart' => 1495580620]];
        $fs->dumpFile($this->dataDir . '/out/state.json', (string) json_encode($data));
        $stateFile = new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->getJobScopedEncryptor(),
            [],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        self::assertEquals($data, $stateFile->loadStateFromFile());
        self::assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }

    public function testLoadStateFromFileEmptyState(): void
    {
        $fs = new Filesystem();
        $data = [];
        $fs->dumpFile($this->dataDir . '/out/state.json', (string) json_encode($data));
        $stateFile = new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->getJobScopedEncryptor(),
            [],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        self::assertEquals(new stdClass(), $stateFile->loadStateFromFile());
        self::assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }

    public function testLoadStateFromFileMissingStateFile(): void
    {
        $stateFile = new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->getJobScopedEncryptor(),
            [],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        self::assertEquals([], $stateFile->loadStateFromFile());
        self::assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }

    public function testPersistStateLogsWarningOn404(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                self::equalTo('branch/default/components/my-component/configs/config-id/rows/row-id/state'),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = $argument['state'];
                    self::assertArrayHasKey(StateFile::NAMESPACE_COMPONENT, $data);
                    self::assertArrayHasKey('key', $data[StateFile::NAMESPACE_COMPONENT]);
                    self::assertEquals('fooBar', $data[StateFile::NAMESPACE_COMPONENT]['key']);
                    return true;
                }),
            )
            ->willThrowException(new ClientException('Test', 404));

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            $logger,
            'row-id',
        );
        $stateFile->stashState(['key' => 'fooBar']);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));

        self::assertTrue($logsHandler->hasWarningThatContains('Failed to store state: Test'));
    }

    public function testPersisStatePassOtherExceptions(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                self::equalTo('branch/default/components/my-component/configs/config-id/rows/row-id/state'),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = $argument['state'];
                    self::assertArrayHasKey(StateFile::NAMESPACE_COMPONENT, $data);
                    self::assertArrayHasKey('key', $data[StateFile::NAMESPACE_COMPONENT]);
                    self::assertEquals('fooBar', $data[StateFile::NAMESPACE_COMPONENT]['key']);
                    return true;
                }),
            )
            ->willThrowException(new ClientException('Test', 888));

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
            'row-id',
        );
        $stateFile->stashState(['key' => 'fooBar']);
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Test');
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }


    public function testPersistStateStoresInputTablesState(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                $this->equalTo('branch/default/components/my-component/configs/config-id/state'),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = $argument['state'];
                    self::assertEquals([
                        StateFile::NAMESPACE_COMPONENT => [],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [
                                    [
                                        'source' => 'in.c-main.test',
                                        'lastImportDate' => 'today',
                                    ],
                                ],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ], $data);
                    return true;
                }),
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
        );
        $stateFile->stashState([]);
        $inputTablesState = new InputTableStateList([
            [
                'source' => 'in.c-main.test',
                'lastImportDate' => 'today',
            ],
        ]);
        $stateFile->persistState($inputTablesState, new InputFileStateList([]));
    }


    public function testRowPersistStateStoresInputTablesState(): void
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                $this->equalTo('branch/default/components/my-component/configs/config-id/rows/row-id/state'),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = $argument['state'];
                    self::assertEquals([
                        StateFile::NAMESPACE_COMPONENT => [],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [
                                    [
                                        'source' => 'in.c-main.test',
                                        'lastImportDate' => 'today',
                                    ],
                                ],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ], $data);
                    return true;
                }),
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            new NullLogger(),
            'row-id',
        );
        $stateFile->stashState([]);
        $inputTablesState = new InputTableStateList([
            [
                'source' => 'in.c-main.test',
                'lastImportDate' => 'today',
            ],
        ]);
        $stateFile->persistState($inputTablesState, new InputFileStateList([]));
    }

    public function testPersistStateUsesBranchClient(): void
    {
        $branchSapiStub = $this->getMockBuilder(BranchAwareClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $branchSapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                self::equalTo('components/my-component/configs/config-id/state'),
                self::equalTo(
                    ['state' => [
                        StateFile::NAMESPACE_COMPONENT => [
                            'key' => 'fooBar',
                        ],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ]],
                ),
            );

        $wrapper = $this->getMockBuilder(ClientWrapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $wrapper->expects(self::once())->method('isDevelopmentBranch')->willReturn(true);
        $wrapper->expects(self::never())->method('getBasicClient');
        $wrapper->expects(self::once())->method('getBranchClient')->willReturn($branchSapiStub);

        $state = ['key' => 'fooBar'];

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        /** @var ClientWrapper $wrapper */
        $stateFile = new StateFile(
            $this->dataDir,
            $wrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => $state],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            $logger,
        );
        $stateFile->stashState($state);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testPersistConfigStateDoesNotOverwriteOtherRootKeys(): void
    {
        $sapiStub = $this->createPartialMock(Client::class, ['apiGet', 'apiPutJson']);
        $sapiStub->expects(self::once())
            ->method('apiGet')
            ->with('branch/default/components/my-component/configs/config-id')
            ->willReturn([
                'configuration' => [
                    'id' => 'config-id',
                ],
                'state' => [
                    'component' => [
                        'foo' => 'bar',
                    ],
                    'storage' => [],
                    'data_apps' => [
                        'key' => 'value',
                    ],
                ],
            ])
        ;
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                self::equalTo('branch/default/components/my-component/configs/config-id/state'),
                self::equalTo(
                    ['state' => [
                        // component & storage is replaced, data_apps is preserved
                        'component' => [
                            'key' => 'fooBar',
                        ],
                        'storage' => [
                            'input' => [
                                'tables' => [],
                                'files' => [],
                            ],
                        ],
                        'data_apps' => [
                            'key' => 'value',
                        ],
                    ]],
                ),
            );

        $state = ['key' => 'fooBar'];

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => $state],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            $logger,
        );
        $stateFile->stashState($state);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testPersistConfigRowStateDoesNotOverwriteOtherRootKeys(): void
    {
        $sapiStub = $this->createPartialMock(Client::class, ['apiGet', 'apiPutJson']);
        $sapiStub->expects(self::once())
            ->method('apiGet')
            ->with('branch/default/components/my-component/configs/config-id/rows/row-id')
            ->willReturn([
                'configuration' => [
                    'id' => 'config-id',
                ],
                'state' => [
                    'component' => [
                        'foo' => 'bar',
                    ],
                    'storage' => [],
                    'data_apps' => [
                        'key' => 'value',
                    ],
                ],
            ])
        ;
        $sapiStub->expects(self::once())
            ->method('apiPutJson')
            ->with(
                self::equalTo('branch/default/components/my-component/configs/config-id/rows/row-id/state'),
                self::equalTo(
                    ['state' => [
                        // component & storage is replaced, data_apps is preserved
                        'component' => [
                            'key' => 'fooBar',
                        ],
                        'storage' => [
                            'input' => [
                                'tables' => [],
                                'files' => [],
                            ],
                        ],
                        'data_apps' => [
                            'key' => 'value',
                        ],
                    ]],
                ),
            );

        $state = ['key' => 'fooBar'];

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => $state],
            'json',
            'my-component',
            'config-id',
            new NullFilter(),
            $logger,
            'row-id',
        );
        $stateFile->stashState($state);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testStateIsNotPersistedWhenConfigIdIsNotSet(): void
    {
        $sapiStub = $this->createPartialMock(Client::class, ['apiPutJson']);
        $sapiStub->expects(self::never())->method('apiPutJson');

        $state = ['key' => 'fooBar'];

        $logsHandler = new TestHandler();
        $logger = new Logger('test', [$logsHandler]);

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->getJobScopedEncryptor(),
            [StateFile::NAMESPACE_COMPONENT => $state],
            'json',
            'my-component',
            null,
            new NullFilter(),
            $logger,
            'row-id',
        );
        $stateFile->stashState($state);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }
}
