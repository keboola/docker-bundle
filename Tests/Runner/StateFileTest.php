<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\OutputFilter\NullFilter;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\ClientException;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class StateFileTest extends TestCase
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var Temp
     */
    private $temp;
    /**
     * @var string
     */
    private $dataDir;

    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    public function setUp()
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);

        // Create folders
        $this->temp = new Temp('docker');
        $fs = new Filesystem();
        $this->dataDir = $this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'data';
        $fs->mkdir($this->dataDir);
        $fs->mkdir($this->dataDir . DIRECTORY_SEPARATOR . 'in');
        $fs->mkdir($this->dataDir . DIRECTORY_SEPARATOR . 'out');

        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $this->encryptorFactory = new ObjectEncryptorFactory(
            AWS_KMS_TEST_KEY,
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $this->encryptorFactory->setStackId('test');
        $this->encryptorFactory->setComponentId('docker-demo');
        $this->encryptorFactory->setProjectId('123');
    }

    public function testCreateStateFileFromStateWithNamespace()
    {
        $stateFile = new StateFile(
            $this->dataDir,
            $this->client,
            $this->encryptorFactory,
            [
                'otherNamespace' => 'value',
                StateFile::NAMESPACE_PREFIX => ['lastUpdate' => 'today']
            ],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
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

    public function testCreateStateFileFromStateWithoutNamespace()
    {
        $stateFile = new StateFile(
            $this->dataDir,
            $this->client,
            $this->encryptorFactory,
            ['lastUpdate' => 'today'],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
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

    public function testCreateStateFileWithEmptyState()
    {
        $stateFile = new StateFile(
            $this->dataDir,
            $this->client,
            $this->encryptorFactory,
            [],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
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
        $sapiStub->expects($this->never())
            ->method('apiPut');

        $state = ['key' => 'fooBar'];
        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            $state,
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
        );
        $stateFile->stashState($state);
        $stateFile->persistState();
    }

    public function testPersistStateConvertsLegacyToNamespace()
    {
        $sapiStub = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                $this->equalTo("storage/components/docker-demo/configs/config-id"),
                ['state' => '{"' . StateFile::NAMESPACE_PREFIX . '":{"key":"fooBar","foo":"bar"}}']
            );
        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            ['key' => 'fooBarBaz'],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
        );
        $stateFile->stashState(["key" => "fooBar", "foo" => "bar"]);
        $stateFile->persistState();
    }


    public function testRowPersistStateConvertsLegacyToNamespace()
    {
        $sapiStub = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                $this->equalTo("storage/components/docker-demo/configs/config-id/rows/row-id"),
                ['state' => '{"' . StateFile::NAMESPACE_PREFIX . '":{"key":"fooBar","foo":"bar"}}']
            );
        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            ['key' => 'fooBarBaz'],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter(),
            'row-id'
        );
        $stateFile->stashState(["key" => "fooBar", "foo" => "bar"]);
        $stateFile->persistState();
    }

    public function testPersistStateEncrypts()
    {
        $sapiStub = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                $this->equalTo("storage/components/docker-demo/configs/config-id"),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = \GuzzleHttp\json_decode($argument['state'], true);
                    self::assertArrayHasKey('key', $data[StateFile::NAMESPACE_PREFIX]);
                    self::assertEquals('fooBar', $data[StateFile::NAMESPACE_PREFIX]['key']);
                    self::assertArrayHasKey('#foo', $data[StateFile::NAMESPACE_PREFIX]);
                    self::assertStringStartsWith('KBC::ProjectSecure::', $data[StateFile::NAMESPACE_PREFIX]['#foo']);
                    return true;
                })
            );

        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            ['key' => 'fooBarBaz'],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
        );
        $stateFile->stashState(["key" => "fooBar", "#foo" => "bar"]);
        $stateFile->persistState();
    }

    public function testStashStateDoesNotUpdate()
    {
        $sapiStub = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::never())
            ->method('apiPut');

        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            ['state' => 'fooBarBaz'],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
        );
        $stateFile->stashState(["state" => "fooBar", "#foo" => "bar"]);
    }

    public function testPersistsStateUpdatesFromEmpty()
    {
        $sapiStub = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('storage/components/docker-demo/configs/config-id'),
                self::equalTo(['state' => '{"' . StateFile::NAMESPACE_PREFIX . '":{"key":"fooBar"}}'])
            );

        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            [],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
        );
        $stateFile->stashState(['key' => 'fooBar']);
        $stateFile->persistState();
    }

    public function testPersistsStateUpdatesToEmptyArray()
    {
        $sapiStub = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('storage/components/docker-demo/configs/config-id'),
                self::equalTo(['state' => '{"' . StateFile::NAMESPACE_PREFIX . '":[]}'])
            );

        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            ['state' => 'fooBar'],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
        );
        $stateFile->stashState([]);
        $stateFile->persistState();
    }

    public function testPersistsStateUpdatesToEmptyObject()
    {
        $sapiStub = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('storage/components/docker-demo/configs/config-id'),
                self::equalTo(['state' => '{"' . StateFile::NAMESPACE_PREFIX . '":{}}'])
            );

        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            ['state' => 'fooBar'],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
        );
        $stateFile->stashState(new \stdClass());
        $stateFile->persistState();
    }

    public function testLoadStateFromFile()
    {
        $fs = new Filesystem();
        $data = ['time' => ['previousStart' => 1495580620]];
        $stateFile = \GuzzleHttp\json_encode($data);
        $fs->dumpFile($this->dataDir . '/out/state.json', $stateFile);
        $stateFile = new StateFile(
            $this->dataDir,
            $this->client,
            $this->encryptorFactory,
            [],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
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
            $this->client,
            $this->encryptorFactory,
            [],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
        );
        self::assertEquals(new \stdClass(), $stateFile->loadStateFromFile());
        self::assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }

    public function testLoadStateFromFileMissingStateFile()
    {
        $stateFile = new StateFile(
            $this->dataDir,
            $this->client,
            $this->encryptorFactory,
            [],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter()
        );
        self::assertEquals([], $stateFile->loadStateFromFile());
        self::assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }

    public function testPersistStateThrowsAnExceptionOn404()
    {
        $sapiStub = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('storage/components/docker-demo/configs/config-id/rows/row-id'),
                self::equalTo(['state' => '{"' . StateFile::NAMESPACE_PREFIX . '":{"state":"fooBar"}}'])
            )
            ->willThrowException(new ClientException("Test", 404));

        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            ['state' => 'fooBarBaz'],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter(),
            'row-id'
        );
        $stateFile->stashState(['state' => 'fooBar']);
        $this->expectException(UserException::class);
        $this->expectExceptionMessage("Failed to store state: Test");
        $stateFile->persistState();
    }

    public function testPersisStatePassOtherExceptions()
    {
        $sapiStub = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects(self::once())
            ->method('apiPut')
            ->with(
                self::equalTo('storage/components/docker-demo/configs/config-id/rows/row-id'),
                self::equalTo(['state' => '{"' . StateFile::NAMESPACE_PREFIX . '":{"state":"fooBar"}}'])
            )
            ->willThrowException(new ClientException("Test", 888));

        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            ['state' => 'fooBarBaz'],
            'json',
            'docker-demo',
            'config-id',
            new NullFilter(),
            'row-id'
        );
        $stateFile->stashState(['state' => 'fooBar']);
        $this->expectException(ClientException::class);
        $this->expectExceptionMessage("Test");
        $stateFile->persistState();
    }
}
