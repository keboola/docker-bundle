<?php

namespace Keboola\DockerBundle\Tests\Job\Metadata;

use Keboola\DockerBundle\Tests\Docker\Mock\ObjectEncryptor;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\DockerBundle\Job\Metadata\JobFactory;
use Keboola\Syrup\Service\StorageApi\StorageApiService;

class JobFactoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @param bool $encrypt
     * @return Client
     */
    protected function getSapiStub($encrypt = true)
    {
        $flags = [];
        if ($encrypt) {
            $flags = ["encrypt"];
        }

        $storageClientStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();

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

        $storageApiClient = new Client([
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);
        $tokenData = $storageApiClient->verifyToken();

        $storageClientStub->expects($this->any())
            ->method("verifyToken")
            ->will($this->returnValue($tokenData));
        $storageClientStub->expects($this->any())
            ->method("getTokenString")
            ->will($this->returnValue($storageApiClient->getTokenString()));
        return $storageClientStub;
    }

    protected function getSapiServiceStub($encrypt = true)
    {
        $storageApiClient = new Client([
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);

        $tokenData = $storageApiClient->verifyToken();

        $storageServiceStub = $this->getMockBuilder("\\Keboola\\Syrup\\Service\\StorageApi\\StorageApiService")
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->any())
            ->method("getClient")
            ->will($this->returnValue($this->getSapiStub($encrypt)));
        $storageServiceStub->expects($this->any())
            ->method("getTokenData")
            ->will($this->returnValue($tokenData));

        return $storageServiceStub;
    }

    public function testJobFactory()
    {
        $tokenData = $this->getSapiServiceStub()->getTokenData();

        $objectEncryptor = new ObjectEncryptor();
        $objectEncryptor->pushWrapper(new BaseWrapper(md5(uniqid())));
        $jobFactory = new JobFactory('docker-bundle', $objectEncryptor, $this->getSapiServiceStub());

        $command = uniqid();
        $param = uniqid();
        $lock = uniqid();


        $job = $jobFactory->create($command, ['configData' => $param, 'component' => 'docker-dummy-test'], $lock);

        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals(['configData' => $param, 'component' => 'docker-dummy-test'], $job->getParams());
        $this->assertArrayHasKey('id', $job->getProject());
        $this->assertEquals($tokenData['owner']['id'], $job->getProject()['id']);
        $this->assertArrayHasKey('name', $job->getProject());
        $this->assertEquals($tokenData['owner']['name'], $job->getProject()['name']);
        $this->assertArrayHasKey('id', $job->getToken());
        $this->assertEquals($tokenData['id'], $job->getToken()['id']);
        $this->assertArrayHasKey('description', $job->getToken());
        $this->assertEquals($tokenData['description'], $job->getToken()['description']);
        $this->assertArrayHasKey('token', $job->getToken());
        $this->assertEquals($tokenData['token'], $objectEncryptor->decrypt($job->getToken()['token']));
    }

    public function testJobEncryptFlagSandbox()
    {
        $objectEncryptor = new ObjectEncryptor();
        $objectEncryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $jobFactory = new JobFactory('docker-bundle', $objectEncryptor, $this->getSapiServiceStub());

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

        $job = $jobFactory->create($command, $objectEncryptor->encrypt($param), $lock);
        $this->assertFalse($job->isEncrypted());
        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals("KBC::Encrypted==", substr($job->getRawParams()["configData"]["#key2"], 0, 16));
        $this->assertEquals("KBC::Encrypted==", substr($job->getParams()["configData"]["#key2"], 0, 16));
    }

    public function testJobEncryptFlagNonEncryptComponent()
    {
        $objectEncryptor = new ObjectEncryptor();
        $objectEncryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $jobFactory = new JobFactory('docker-bundle', $objectEncryptor, $this->getSapiServiceStub(false));

        $command = uniqid();
        $param = [
            "configData" => [
                "key1" => "value1",
                "#key2" => "value2"
            ],
            "component" => "docker-dummy-test"
        ];
        $lock = uniqid();

        $job = $jobFactory->create($command, $objectEncryptor->encrypt($param), $lock);
        $this->assertFalse($job->isEncrypted());
        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals("KBC::Encrypted==", substr($job->getRawParams()["configData"]["#key2"], 0, 16));
        $this->assertEquals("KBC::Encrypted==", substr($job->getParams()["configData"]["#key2"], 0, 16));
    }

    public function testJobEncryptFlagEncryptComponent()
    {
        $objectEncryptor = new ObjectEncryptor();
        $objectEncryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $jobFactory = new JobFactory('docker-bundle', $objectEncryptor, $this->getSapiServiceStub());

        $command = uniqid();
        $param = [
            "configData" => [
                "key1" => "value1",
                "#key2" => "value2"
            ],
            "component" => "docker-dummy-test"
        ];
        $lock = uniqid();

        $job = $jobFactory->create($command, $objectEncryptor->encrypt($param), $lock);
        $this->assertTrue($job->isEncrypted());
        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals("KBC::Encrypted==", substr($job->getRawParams()["configData"]["#key2"], 0, 16));
        $this->assertEquals("value2", $job->getParams()["configData"]["#key2"]);
    }
}
