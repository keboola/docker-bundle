<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\OutputFilter\NullFilter;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
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

    public function testCreateStateFile()
    {
        $state = ['lastUpdate' => 'today'];
        $stateFile = new StateFile(
            $this->dataDir,
            $this->client,
            $this->encryptorFactory,
            $state,
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
            json_decode(file_get_contents($fileName), false)
        );
    }

    public function testCreateEmptyStateFile()
    {
        $state = [];
        $stateFile = new StateFile(
            $this->dataDir,
            $this->client,
            $this->encryptorFactory,
            $state,
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
            json_decode(file_get_contents($fileName), false)
        );
    }

    public function testUpdateStateNoChange()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->never())
            ->method('apiPut')
        ;

        $state = ['state' => 'fooBar'];
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
        $stateFile->storeState($state);
    }

    public function testUpdateStateChange()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method('apiPut')
            ->with(
                $this->equalTo("storage/components/docker-demo/configs/config-id"),
                $this->callback(function ($argument) {
                    self::assertArrayHasKey('state', $argument);
                    $data = \GuzzleHttp\json_decode($argument['state'], true);
                    self::assertArrayHasKey('state', $data);
                    self::assertEquals('fooBar', $data['state']);
                    self::assertArrayHasKey('#foo', $data);
                    self::assertStringStartsWith('KBC::ProjectSecure::', $data['#foo']);
                    return true;
                })
            );

        $state = ['state' => 'fooBarBaz'];
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
        $stateFile->storeState(["state" => "fooBar", "#foo" => "bar"]);
    }

    public function testUpdateStateChangeFromEmpty()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method('apiPut')
            ->with(
                $this->equalTo('storage/components/docker-demo/configs/config-id'),
                $this->equalTo(['state' => '{"state":"fooBar"}'])
            );

        $state = [];
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
        $stateFile->storeState(['state' => 'fooBar']);
    }

    public function testUpdateStateChangeToEmptyArray()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method('apiPut')
            ->with(
                $this->equalTo('storage/components/docker-demo/configs/config-id'),
                $this->equalTo(['state' => '[]'])
            )
        ;

        $state = ['state' => 'fooBar'];
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
        $stateFile->storeState([]);
    }

    public function testUpdateStateChangeToEmptyObject()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method('apiPut')
            ->with(
                $this->equalTo('storage/components/docker-demo/configs/config-id'),
                $this->equalTo(['state' => '{}'])
            )
        ;

        $state = ['state' => 'fooBar'];
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
        $stateFile->storeState(new \stdClass());
    }

    public function testUpdateRowStateChange()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method('apiPut')
            ->with(
                $this->equalTo('storage/components/docker-demo/configs/config-id/rows/row-id'),
                $this->equalTo(['state' => '{"state":"fooBar"}'])
            );

        $state = ['state' => 'fooBarBaz'];
        /** @var Client $sapiStub */
        $stateFile = new StateFile(
            $this->dataDir,
            $sapiStub,
            $this->encryptorFactory,
            $state,
            'json',
            'docker-demo',
            'config-id',
            new NullFilter(),
            'row-id'
        );
        $stateFile->storeState(['state' => 'fooBar']);
    }

    public function testPickState()
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

    public function testPickStateEmptyState()
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

    public function testPickStateNoState()
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
}
