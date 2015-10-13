<?php

namespace Keboola\DockerBundle\Tests\Job\Metadata;

use Keboola\StorageApi\Client;
use Keboola\Syrup\Encryption\CryptoWrapper;
use Keboola\Syrup\Encryption\Encryptor;
use Keboola\DockerBundle\Job\Metadata\JobFactory;
use Keboola\Syrup\Service\ObjectEncryptor;

class JobTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @covers \Keboola\DockerBundle\Job\Metadata\Job::getParams
     */
    public function testGetParams()
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
        $param = ["key1" => "value1", "#key2" => "value2"];
        $lock = uniqid();

        $job = $jobFactory->create($command, $configEncryptor->encrypt($param), $lock);

        $this->assertEquals($command, $job->getCommand());
        $this->assertEquals($lock, $job->getLockName());
        $this->assertEquals("KBC::Encrypted==", substr($job->getData()["params"]["#key2"], 0, 16));
        $this->assertEquals($param, $job->getParams());
    }
}
