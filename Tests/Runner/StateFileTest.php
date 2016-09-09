<?php

namespace Keboola\DockerBundle\Tests\Runner;

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
        $stateFile = new StateFile($this->dataDir, $this->client, $state, 'docker-demo', 'config-id', 'json');
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
        $stateFile = new StateFile($this->dataDir, $this->client, $state, 'docker-demo', 'config-id', 'json');
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
        $stateFile = new StateFile($this->dataDir, $sapiStub, $state, 'docker-demo', 'config-id', 'json');
        $fileName = $this->dataDir . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'state.json';
        file_put_contents($fileName, json_encode($state));

        $stateFile->storeStateFile();
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
        $stateFile = new StateFile($this->dataDir, $sapiStub, $state, 'docker-demo', 'config-id', 'json');
        $state = ["state" => "fooBar"];
        $fileName = $this->dataDir . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'state.json';
        file_put_contents($fileName, json_encode($state));

        $stateFile->storeStateFile();
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
        $stateFile = new StateFile($this->dataDir, $sapiStub, $state, 'docker-demo', 'config-id', 'json');
        $state = ["state" => "fooBar"];
        $fileName = $this->dataDir . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'state.json';
        file_put_contents($fileName, json_encode($state));

        $stateFile->storeStateFile();
    }

    public function testUpdateStateChangeToEmpty()
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
        $stateFile = new StateFile($this->dataDir, $sapiStub, $state, 'docker-demo', 'config-id', 'json');
        $state = [];
        $fileName = $this->dataDir . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR . 'state.json';
        file_put_contents($fileName, json_encode($state));

        $stateFile->storeStateFile();
    }
}
