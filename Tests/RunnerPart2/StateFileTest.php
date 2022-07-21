<?php

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\DockerBundle\Docker\OutputFilter\NullFilter;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\DockerBundle\Exception\UserException;
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Psr\Log\Test\TestLogger;
use Symfony\Component\Filesystem\Filesystem;

class StateFileTest extends TestCase
{
    private string $dataDir;
    private ObjectEncryptor $encryptor;
    private ClientWrapper $clientWrapper;

    public function setUp(): void
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);

        // Create folders
        $temp = new Temp('docker');
        $fs = new Filesystem();
        $this->dataDir = $temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'data';
        $fs->mkdir($this->dataDir);
        $fs->mkdir($this->dataDir . DIRECTORY_SEPARATOR . 'in');
        $fs->mkdir($this->dataDir . DIRECTORY_SEPARATOR . 'out');

        $this->clientWrapper = new ClientWrapper(new ClientOptions(
            STORAGE_API_URL,
            STORAGE_API_TOKEN,
        ));

        $this->encryptor = ObjectEncryptorFactory::getEncryptor(new EncryptorOptions(
            parse_url(STORAGE_API_URL, PHP_URL_HOST),
            AWS_KMS_TEST_KEY,
            AWS_ECR_REGISTRY_REGION,
            null,
            null,
        ));
    }

    public function testCreateStateFileFromStateWithNamespace()
    {
        $stateFile = new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->encryptor,
            [
                StateFile::NAMESPACE_COMPONENT => ['lastUpdate' => 'today']
            ],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        $stateFile->createStateFile();
        $fileName = $this->dataDir . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'state.json';
        self::assertTrue(file_exists($fileName));
        $obj = new \stdClass();
        $obj->lastUpdate = 'today';
        self::assertEquals(
            $obj,
            \GuzzleHttp\json_decode(file_get_contents($fileName), false)
        );
    }

    public function testInitializeStateFileFromStateWithoutNamespace()
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Unrecognized option "lastUpdate" under "state"');
        new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->encryptor,
            ['lastUpdate' => 'today'],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
    }

    public function testCreateStateFileWithEmptyState()
    {
        $stateFile = new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->encryptor,
            [],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        $stateFile->createStateFile();
        $fileName = $this->dataDir . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'state.json';
        self::assertTrue(file_exists($fileName));
        self::assertEquals(
            new \stdClass(),
            \GuzzleHttp\json_decode(file_get_contents($fileName), false)
        );
    }

    public function testPersistStateWithoutStateChange()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('components/docker-demo/configs/config-id/state'),
                self::equalTo(
                    ['state' => json_encode([
                        StateFile::NAMESPACE_COMPONENT => [
                            'key' => 'fooBar',
                        ],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ])]
                )
            );

        $state = ['key' => 'fooBar'];
        $testLogger = new TestLogger();
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);
        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => $state],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            $testLogger
        );
        $stateFile->stashState($state);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }


    public function testPersistStateLogsSavingState()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut');
        $testLogger = new TestLogger();
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            $testLogger
        );
        $stateFile->stashState(["key" => "fooBar", "foo" => "bar"]);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
        self::assertTrue($testLogger->hasRecord('Storing state: {"component":{"key":"fooBar","foo":"bar"},"storage":{"input":{"tables":[],"files":[]}}}', LogLevel::NOTICE));
    }

    public function testPersistStateEncrypts()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                $this->equalTo("components/docker-demo/configs/config-id/state"),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = \GuzzleHttp\json_decode($argument['state'], true);
                    self::assertArrayHasKey('key', $data[StateFile::NAMESPACE_COMPONENT]);
                    self::assertEquals('fooBar', $data[StateFile::NAMESPACE_COMPONENT]['key']);
                    self::assertArrayHasKey('#foo', $data[StateFile::NAMESPACE_COMPONENT]);
                    self::assertStringStartsWith('KBC::ProjectSecure::', $data[StateFile::NAMESPACE_COMPONENT]['#foo']);
                    return true;
                })
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        $stateFile->stashState(["key" => "fooBar", "#foo" => "bar"]);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testStashStateDoesNotUpdate()
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
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        $stateFile->stashState(["key" => "fooBar", "#foo" => "bar"]);
    }

    public function testPersistsStateUpdatesFromEmpty()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('components/docker-demo/configs/config-id/state'),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = \GuzzleHttp\json_decode($argument['state'], true);
                    self::assertArrayHasKey(StateFile::NAMESPACE_COMPONENT, $data);
                    self::assertArrayHasKey('key', $data[StateFile::NAMESPACE_COMPONENT]);
                    self::assertEquals('fooBar', $data[StateFile::NAMESPACE_COMPONENT]['key']);
                    return true;
                })
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        $stateFile->stashState(['key' => 'fooBar']);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testPersistsStateUpdatesToEmptyArray()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('components/docker-demo/configs/config-id/state'),
                self::equalTo(
                    ['state' => json_encode([
                        StateFile::NAMESPACE_COMPONENT => [],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ])]
                )
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBar']],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        $stateFile->stashState([]);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testPersistsStateUpdatesToEmptyObject()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('components/docker-demo/configs/config-id/state'),
                self::equalTo(
                    ['state' => json_encode([
                        StateFile::NAMESPACE_COMPONENT => new \stdClass(),
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ])]
                )
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBar']],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        $stateFile->stashState(new \stdClass());
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testPersistsStateSavesUnchangedState()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('components/docker-demo/configs/config-id/state'),
                self::equalTo(
                    ['state' => json_encode([
                        StateFile::NAMESPACE_COMPONENT => [
                            'key' => 'fooBar'
                        ],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ])]
                )
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBar']],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        $stateFile->stashState(['key' => 'fooBar']);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testLoadStateFromFile()
    {
        $fs = new Filesystem();
        $data = ['time' => ['previousStart' => 1495580620]];
        $stateFile = \GuzzleHttp\json_encode($data);
        $fs->dumpFile($this->dataDir . '/out/state.json', $stateFile);
        $stateFile = new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->encryptor,
            [],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        self::assertEquals($data, $stateFile->loadStateFromFile());
        self::assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }

    public function testLoadStateFromFileEmptyState()
    {
        $fs = new Filesystem();
        $data = [];
        $stateFile = \GuzzleHttp\json_encode($data);
        $fs->dumpFile($this->dataDir . '/out/state.json', $stateFile);
        $stateFile = new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->encryptor,
            [],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        self::assertEquals(new \stdClass(), $stateFile->loadStateFromFile());
        self::assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }

    public function testLoadStateFromFileMissingStateFile()
    {
        $stateFile = new StateFile(
            $this->dataDir,
            $this->clientWrapper,
            $this->encryptor,
            [],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        self::assertEquals([], $stateFile->loadStateFromFile());
        self::assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }

    public function testPersistStateThrowsAnExceptionOn404()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('components/docker-demo/configs/config-id/rows/row-id/state'),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = \GuzzleHttp\json_decode($argument['state'], true);
                    self::assertArrayHasKey(StateFile::NAMESPACE_COMPONENT, $data);
                    self::assertArrayHasKey('key', $data[StateFile::NAMESPACE_COMPONENT]);
                    self::assertEquals('fooBar', $data[StateFile::NAMESPACE_COMPONENT]['key']);
                    return true;
                })
            )
            ->willThrowException(new ClientException("Test", 404));

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger(),
            'row-id'
        );
        $stateFile->stashState(['key' => 'fooBar']);
        $this->expectException(UserException::class);
        $this->expectExceptionMessage("Failed to store state: Test");
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }

    public function testPersisStatePassOtherExceptions()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('components/docker-demo/configs/config-id/rows/row-id/state'),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = \GuzzleHttp\json_decode($argument['state'], true);
                    self::assertArrayHasKey(StateFile::NAMESPACE_COMPONENT, $data);
                    self::assertArrayHasKey('key', $data[StateFile::NAMESPACE_COMPONENT]);
                    self::assertEquals('fooBar', $data[StateFile::NAMESPACE_COMPONENT]['key']);
                    return true;
                })
            )
            ->willThrowException(new ClientException("Test", 888));

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger(),
            'row-id'
        );
        $stateFile->stashState(['key' => 'fooBar']);
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage("Test");
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }


    public function testPersistStateStoresInputTablesState()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                $this->equalTo("components/docker-demo/configs/config-id/state"),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = \GuzzleHttp\json_decode($argument['state'], true);
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
                })
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger()
        );
        $stateFile->stashState([]);
        $inputTablesState = new InputTableStateList([
            [
                'source' => 'in.c-main.test',
                'lastImportDate' => 'today'
            ]
        ]);
        $stateFile->persistState($inputTablesState, new InputFileStateList([]));
    }


    public function testRowPersistStateStoresInputTablesState()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                $this->equalTo("components/docker-demo/configs/config-id/rows/row-id/state"),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = \GuzzleHttp\json_decode($argument['state'], true);
                    self::assertEquals([
                        StateFile::NAMESPACE_COMPONENT => [],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [
                                    [
                                        'source' => 'in.c-main.test',
                                        'lastImportDate' => 'today'
                                    ]
                                ],
                                StateFile::NAMESPACE_FILES => [],
                            ]
                        ]
                    ], $data);
                    return true;
                })
            );
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->method('getBasicClient')->willReturn($sapiStub);

        $stateFile = new StateFile(
            $this->dataDir,
            $clientWrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => ['key' => 'fooBarBaz']],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            new NullLogger(),
            'row-id'
        );
        $stateFile->stashState([]);
        $inputTablesState = new InputTableStateList([
            [
                'source' => 'in.c-main.test',
                'lastImportDate' => 'today'
            ]
        ]);
        $stateFile->persistState($inputTablesState, new InputFileStateList([]));
    }

    public function testPersistStateUsesBranchClient()
    {
        $branchSapiStub = $this->getMockBuilder(BranchAwareClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $branchSapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('components/docker-demo/configs/config-id/state'),
                self::equalTo(
                    ['state' => json_encode([
                        StateFile::NAMESPACE_COMPONENT => [
                            'key' => 'fooBar'
                        ],
                        StateFile::NAMESPACE_STORAGE => [
                            StateFile::NAMESPACE_INPUT => [
                                StateFile::NAMESPACE_TABLES => [],
                                StateFile::NAMESPACE_FILES => [],
                            ],
                        ],
                    ])]
                )
            );

        $wrapper = $this->getMockBuilder(ClientWrapper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $wrapper->expects(self::once())->method('hasBranch')->willReturn(true);
        $wrapper->expects(self::never())->method('getBasicClient');
        $wrapper->expects(self::once())->method('getBranchClient')->willReturn($branchSapiStub);

        $state = ['key' => 'fooBar'];
        $testLogger = new TestLogger();
        /** @var ClientWrapper $wrapper */
        $stateFile = new StateFile(
            $this->dataDir,
            $wrapper,
            $this->encryptor,
            [StateFile::NAMESPACE_COMPONENT => $state],
            'json',
            'docker-demo',
            'project-id',
            'config-id',
            new NullFilter(),
            $testLogger
        );
        $stateFile->stashState($state);
        $stateFile->persistState(new InputTableStateList([]), new InputFileStateList([]));
    }
}
