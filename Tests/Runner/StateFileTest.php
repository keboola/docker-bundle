<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\OutputFilter\NullFilter;
use Keboola\DockerBundle\Docker\Runner\StateFile;
use Keboola\StorageApi\Client;
use Keboola\Temp\Temp;
use Symfony\Component\Filesystem\Filesystem;

class StateFileTest extends \PHPUnit_Framework_TestCase
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

    public function setUp()
    {
        parent::setUp();
        // Create folders
        $this->temp = new Temp('docker');
        $fs = new Filesystem();
        $this->dataDir = $this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'data';
        $fs->mkdir($this->dataDir);
        $fs->mkdir($this->dataDir . DIRECTORY_SEPARATOR . 'in');
        $fs->mkdir($this->dataDir . DIRECTORY_SEPARATOR . 'out');

        $this->client = new Client([
            'url' => STORAGE_API_URL,
            "token" => STORAGE_API_TOKEN,
        ]);
    }

    public function tearDown()
    {
        parent::tearDown();
        $fs = new Filesystem();
        $fs->remove($this->dataDir);
        $this->temp = null;
    }

    public function testCreateStateFile()
    {
        $state = ['lastUpdate' => 'today'];
        $stateFile = new StateFile($this->dataDir, $this->client, $state, 'json', 'docker-demo', 'config-id', new NullFilter());
        $stateFile->createStateFile();
        $fileName = $this->dataDir . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'state.json';
        $this->assertTrue(file_exists($fileName));
        $obj = new \stdClass();
        $obj->lastUpdate = 'today';
        $this->assertEquals(
            $obj,
            json_decode(file_get_contents($fileName), false)
        );
    }

    public function testCreateEmptyStateFile()
    {
        $state = [];
        $stateFile = new StateFile($this->dataDir, $this->client, $state, 'json', 'docker-demo', 'config-id', new NullFilter());
        $stateFile->createStateFile();
        $fileName = $this->dataDir . DIRECTORY_SEPARATOR . 'in' . DIRECTORY_SEPARATOR . 'state.json';
        $this->assertTrue(file_exists($fileName));
        $this->assertEquals(
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
            ->method("apiPut")
        ;

        $state = ["state" => "fooBar"];
        /** @var Client $sapiStub */
        $stateFile = new StateFile($this->dataDir, $sapiStub, $state, 'json', 'docker-demo', 'config-id', new NullFilter());
        $stateFile->storeState($state);
    }

    public function testUpdateStateChange()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method("apiPut")
            ->with(
                $this->equalTo("storage/components/docker-demo/configs/config-id"),
                $this->equalTo(["state" => '{"state":"fooBar"}'])
            );

        $state = ["state" => "fooBarBaz"];
        /** @var Client $sapiStub */
        $stateFile = new StateFile($this->dataDir, $sapiStub, $state, 'json', 'docker-demo', 'config-id', new NullFilter());
        $stateFile->storeState(["state" => "fooBar"]);
    }

    public function testUpdateStateChangeFromEmpty()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method("apiPut")
            ->with(
                $this->equalTo("storage/components/docker-demo/configs/config-id"),
                $this->equalTo(["state" => '{"state":"fooBar"}'])
            );

        $state = [];
        /** @var Client $sapiStub */
        $stateFile = new StateFile($this->dataDir, $sapiStub, $state, 'json', 'docker-demo', 'config-id', new NullFilter());
        $stateFile->storeState(["state" => "fooBar"]);
    }

    public function testUpdateStateChangeToEmptyArray()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method("apiPut")
            ->with(
                $this->equalTo("storage/components/docker-demo/configs/config-id"),
                $this->equalTo(["state" => '[]'])
            )
        ;

        $state = ["state" => "fooBar"];
        /** @var Client $sapiStub */
        $stateFile = new StateFile($this->dataDir, $sapiStub, $state, 'json', 'docker-demo', 'config-id', new NullFilter());
        $stateFile->storeState([]);
    }

    public function testUpdateStateChangeToEmptyObject()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method("apiPut")
            ->with(
                $this->equalTo("storage/components/docker-demo/configs/config-id"),
                $this->equalTo(["state" => '{}'])
            )
        ;

        $state = ["state" => "fooBar"];
        /** @var Client $sapiStub */
        $stateFile = new StateFile($this->dataDir, $sapiStub, $state, 'json', 'docker-demo', 'config-id', new NullFilter());
        $stateFile->storeState(new \stdClass());
    }

    public function testUpdateRowStateChange()
    {
        $sapiStub = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->once())
            ->method("apiPut")
            ->with(
                $this->equalTo("storage/components/docker-demo/configs/config-id/rows/row-id"),
                $this->equalTo(["state" => '{"state":"fooBar"}'])
            );

        $state = ["state" => "fooBarBaz"];
        /** @var Client $sapiStub */
        $stateFile = new StateFile($this->dataDir, $sapiStub, $state, 'json', 'docker-demo', 'config-id', new NullFilter(), 'row-id');
        $stateFile->storeState(["state" => "fooBar"]);
    }

    public function testPickState()
    {
        $fs = new Filesystem();
        $data = ["time" => ["previousStart" => 1495580620]];
        $stateFile = \GuzzleHttp\json_encode($data);
        $fs->dumpFile($this->dataDir . '/out/state.json', $stateFile);
        $stateFile = new StateFile($this->dataDir, $this->client, [], 'json', 'docker-demo', 'config-id', new NullFilter());
        $this->assertEquals($data, $stateFile->loadStateFromFile());
        $this->assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }

    public function testPickStateEmptyState()
    {
        $fs = new Filesystem();
        $data = [];
        $stateFile = \GuzzleHttp\json_encode($data);
        $fs->dumpFile($this->dataDir . '/out/state.json', $stateFile);
        $stateFile = new StateFile($this->dataDir, $this->client, [], 'json', 'docker-demo', 'config-id', new NullFilter());
        $this->assertEquals(new \stdClass(), $stateFile->loadStateFromFile());
        $this->assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }

    public function testPickStateNoState()
    {
        $stateFile = new StateFile($this->dataDir, $this->client, [], 'json', 'docker-demo', 'config-id', new NullFilter());
        $this->assertEquals([], $stateFile->loadStateFromFile());
        $this->assertFalse(file_exists($this->dataDir . '/out/state.json'));
    }
}
