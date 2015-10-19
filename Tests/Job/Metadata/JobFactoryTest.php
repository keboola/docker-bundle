<?php

namespace Keboola\DockerBundle\Tests\Job\Metadata;

use Keboola\StorageApi\Client;
use Keboola\Syrup\Encryption\CryptoWrapper;
use Keboola\Syrup\Encryption\Encryptor;
use Keboola\DockerBundle\Job\Metadata\JobFactory;
use Keboola\Syrup\Service\ObjectEncryptor;

class JobFactoryTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @covers \Keboola\DockerBundle\Job\Metadata\JobFactory::create
     * @covers \Keboola\DockerBundle\Job\Metadata\JobFactory::setStorageApiClient
     */
    public function testJobFactory()
    {
        $storageApiClient = new Client([
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);

        $key = sha1(uniqid());
        $encryptor = new Encryptor(substr($key, 0, 32));
        $configEncryptor = new ObjectEncryptor(new CryptoWrapper($key));


        // mock client to return image data
        $indexActionValue = array(
            'components' =>
                array (
                    0 =>
                        array (
                            'id' => 'docker-config-dump',
                            'type' => 'other',
                            'name' => 'Docker Config Dump',
                            'description' => 'Testing Docker',
                            'longDescription' => null,
                            'hasUI' => false,
                            'hasRun' => true,
                            'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png',
                            'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-64-1.png',
                            'data' => array (
                                'definition' =>
                                    array (
                                        'type' => 'dockerhub',
                                        'uri' => 'keboola/config-dump',
                                    ),
                            ),
                            'flags' => array ('encrypt'),
                            'uri' => 'https://syrup.keboola.com/docker/docker-config-dump',
                        )
                )
        );
        $sapiStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $sapiStub->expects($this->any())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));

        $storageServiceStub = $this->getMockBuilder("\\Keboola\\Syrup\\Service\\StorageApi\\StorageApiService")
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->atLeastOnce())
            ->method("getClient")
            ->will($this->returnValue($sapiStub));

        $jobFactory = new JobFactory('docker-bundle', $encryptor, $configEncryptor, $storageServiceStub);
        $jobFactory->setStorageApiClient($storageApiClient);

        $command = uniqid();
        $param = uniqid();
        $lock = uniqid();
        $tokenData = $storageApiClient->verifyToken();

        $job = $jobFactory->create($command, ['configData' => $param, 'component' => 'docker-config-dump'], $lock);

        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals(['configData' => $param, 'component' => 'docker-config-dump'], $job->getParams());
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
}
