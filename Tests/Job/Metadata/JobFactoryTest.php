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

        $key = md5(uniqid());
        $encryptor = new Encryptor($key);
        $configEncryptor = new ObjectEncryptor(new CryptoWrapper($key));
        $jobFactory = new JobFactory('docker-bundle', $encryptor, $configEncryptor);
        $jobFactory->setStorageApiClient($storageApiClient);

        $command = uniqid();
        $param = uniqid();
        $lock = uniqid();
        $tokenData = $storageApiClient->verifyToken();

        $job = $jobFactory->create($command, ['param' => $param], $lock);

        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals(['param' => $param], $job->getParams());
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
