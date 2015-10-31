<?php

namespace Keboola\DockerBundle\Tests\Job\Metadata;

use Keboola\StorageApi\Client;
use Keboola\Syrup\Encryption\Encryptor;
use Keboola\DockerBundle\Job\Metadata\JobFactory;
use Keboola\Syrup\Service\ObjectEncryptor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class JobFactoryTest extends KernelTestCase
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

    public function testJobFactory()
    {
        $storageApiClient = new Client([
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);

        $key = hash('sha256', uniqid());
        $encryptor = new Encryptor(substr($key, 0, 32));
        /** @var ObjectEncryptor $configEncryptor */
        $configEncryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');

        $sapiStub = $this->getSapiStub(true);
        $jobFactory = new JobFactory('docker-bundle', $encryptor, $configEncryptor);
        $jobFactory->setStorageApiClient($sapiStub);

        $command = uniqid();
        $param = uniqid();
        $lock = uniqid();
        $tokenData = $storageApiClient->verifyToken();

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
        $this->assertEquals($tokenData['token'], $encryptor->decrypt($job->getToken()['token']));
    }

    public function testJobEncryptFlagSandbox()
    {
        $key = hash('sha256', uniqid());
        $encryptor = new Encryptor(substr($key, 0, 32));
        /** @var ObjectEncryptor $configEncryptor */
        $configEncryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
        $sapiStub = $this->getSapiStub(true);
        $jobFactory = new JobFactory('docker-bundle', $encryptor, $configEncryptor);
        $jobFactory->setStorageApiClient($sapiStub);

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
        $this->assertFalse($job->isEncrypted());
        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals("KBC::Encrypted==", substr($job->getRawParams()["configData"]["#key2"], 0, 16));
        $this->assertEquals("KBC::Encrypted==", substr($job->getParams()["configData"]["#key2"], 0, 16));
    }

    public function testJobEncryptFlagNonEncryptComponent()
    {
        $key = hash('sha256', uniqid());
        $encryptor = new Encryptor(substr($key, 0, 32));
        /** @var ObjectEncryptor $configEncryptor */
        $configEncryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
        $sapiStub = $this->getSapiStub(false);
        $jobFactory = new JobFactory('docker-bundle', $encryptor, $configEncryptor);
        $jobFactory->setStorageApiClient($sapiStub);

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
        $this->assertFalse($job->isEncrypted());
        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals("KBC::Encrypted==", substr($job->getRawParams()["configData"]["#key2"], 0, 16));
        $this->assertEquals("KBC::Encrypted==", substr($job->getParams()["configData"]["#key2"], 0, 16));
    }

    public function testJobEncryptFlagEncryptComponent()
    {
        $key = hash('sha256', uniqid());
        $encryptor = new Encryptor(substr($key, 0, 32));
        /** @var ObjectEncryptor $configEncryptor */
        $configEncryptor = self::$kernel->getContainer()->get('syrup.object_encryptor');
        $sapiStub = $this->getSapiStub(true);
        $jobFactory = new JobFactory('docker-bundle', $encryptor, $configEncryptor);
        $jobFactory->setStorageApiClient($sapiStub);

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
        $this->assertTrue($job->isEncrypted());
        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals("KBC::Encrypted==", substr($job->getRawParams()["configData"]["#key2"], 0, 16));
        $this->assertEquals("value2", $job->getParams()["configData"]["#key2"]);
    }
}
