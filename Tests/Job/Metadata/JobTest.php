<?php

namespace Keboola\DockerBundle\Tests\Job\Metadata;

use Keboola\StorageApi\Client;
use Keboola\Syrup\Encryption\CryptoWrapper;
use Keboola\Syrup\Encryption\Encryptor;
use Keboola\DockerBundle\Job\Metadata\JobFactory;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;

class JobTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @param bool $encrypt
     * @return StorageApiService
     */
    protected function getSapiServiceStub($encrypt = true)
    {
        $flags = [];
        if ($encrypt) {
            $flags = ["encrypt"];
        }

        $storageServiceStub = $this->getMockBuilder("\\Keboola\\Syrup\\Service\\StorageApi\\StorageApiService")
            ->disableOriginalConstructor()
            ->getMock();
        $storageClientStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->atLeastOnce())
            ->method("getClient")
            ->will($this->returnValue($storageClientStub));

        // mock client to return image data
        $indexActionValue = array(
            'components' =>
                array(
                    0 =>
                        array(
                            'id' => 'docker-dummy-test',
                            'type' => 'other',
                            'name' => 'Docker Config Dump',
                            'description' => 'Testing Docker',
                            'longDescription' => null,
                            'hasUI' => false,
                            'hasRun' => true,
                            'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png',
                            'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-64-1.png',
                            'data' => array(
                                'definition' =>
                                    array(
                                        'type' => 'dockerhub',
                                        'uri' => 'keboola/docker-dummy-test',
                                    ),
                            ),
                            'flags' => $flags,
                            'uri' => 'https://syrup.keboola.com/docker/docker-dummy-test',
                        )
                )
        );

        $storageClientStub->expects($this->any())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->any())
            ->method("verifyToken")
            ->will($this->returnValue(["owner" => ["id" => "123"]]));
        return $storageServiceStub;
    }

    /**
     * @covers \Keboola\DockerBundle\Job\Metadata\Job::getParams
     */
    public function testGetParams()
    {
        $storageApiClient = new Client([
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);

        $key = sha1(uniqid());
        $encryptor = new Encryptor(substr($key, 0, 32));
        $configEncryptor = new ObjectEncryptor(new CryptoWrapper($key));
        $jobFactory = new JobFactory('docker-bundle', $encryptor, $configEncryptor, $this->getSapiServiceStub(true));
        $jobFactory->setStorageApiClient($storageApiClient);

        $command = uniqid();
        $param = [
            "configData" => [
                "key1" => "value1",
                "#key2" => "value2"
            ],
            "component" => "docker-dummy-test"
        ];

        $lock = uniqid();

        $job = $jobFactory->create($command, $configEncryptor->encrypt($param), $lock);

        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals("KBC::Encrypted==", substr($job->getData()["params"]["configData"]["#key2"], 0, 16));
        $this->assertEquals($param, $job->getParams());
    }

    /**
     * @covers \Keboola\DockerBundle\Job\Metadata\Job::getParams
     */
    public function testGetParamsSandbox()
    {
        $storageApiClient = new Client([
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);

        $key = sha1(uniqid());
        $encryptor = new Encryptor(substr($key, 0, 32));
        $configEncryptor = new ObjectEncryptor(new CryptoWrapper($key));
        $jobFactory = new JobFactory('docker-bundle', $encryptor, $configEncryptor, $this->getSapiServiceStub(true));
        $jobFactory->setStorageApiClient($storageApiClient);

        $command = uniqid();
        $param = [
            "configData" => [
                "key1" => "value1",
                "#key2" => "value2"
            ],
            "mode" => "sandbox",
            "component" => "docker-dummy-test"
        ];
        $lock = uniqid();

        $job = $jobFactory->create($command, $configEncryptor->encrypt($param), $lock);

        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals("KBC::Encrypted==", substr($job->getData()["params"]["configData"]["#key2"], 0, 16));
        $this->assertEquals("KBC::Encrypted==", substr($job->getParams()["configData"]["#key2"], 0, 16));
    }

    /**
     * @covers \Keboola\DockerBundle\Job\Metadata\Job::getParams
     */
    public function testGetParamsWithoutEncryptFlag()
    {
        $storageApiClient = new Client([
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);

        $key = sha1(uniqid());
        $encryptor = new Encryptor(substr($key, 0, 32));
        $configEncryptor = new ObjectEncryptor(new CryptoWrapper($key));

        $jobFactory = new JobFactory('docker-bundle', $encryptor, $configEncryptor, $this->getSapiServiceStub(false));
        $jobFactory->setStorageApiClient($storageApiClient);

        $command = uniqid();
        $param = [
            "configData" => [
                "key1" => "value1",
                "#key2" => "value2"
            ],
            "component" => "docker-dummy-test"
        ];
        $lock = uniqid();

        $job = $jobFactory->create($command, $configEncryptor->encrypt($param), $lock);

        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals("KBC::Encrypted==", substr($job->getData()["params"]["configData"]["#key2"], 0, 16));
        $this->assertEquals("KBC::Encrypted==", substr($job->getParams()["configData"]["#key2"], 0, 16));
    }
}
