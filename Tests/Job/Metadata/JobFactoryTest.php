<?php

namespace Keboola\DockerBundle\Tests\Job\Metadata;

use Defuse\Crypto\Key;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\DockerBundle\Job\Metadata\JobFactory;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use PHPUnit\Framework\TestCase;

class JobFactoryTest extends TestCase
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

        $storageClientStub = $this->getMockBuilder(Client::class)
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

        $storageClientStub->expects(self::any())
            ->method("indexAction")
            ->will(self::returnValue($indexActionValue));

        $storageApiClient = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);
        $tokenData = $storageApiClient->verifyToken();

        $storageClientStub->expects(self::any())
            ->method("verifyToken")
            ->will(self::returnValue($tokenData));
        $storageClientStub->expects(self::any())
            ->method("getTokenString")
            ->will(self::returnValue($storageApiClient->getTokenString()));
        /** @var Client $storageClientStub */
        return $storageClientStub;
    }

    /**
     * @param bool $encrypt
     * @return StorageApiService
     */
    protected function getSapiServiceStub($encrypt = true)
    {
        $storageApiClient = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);

        $tokenData = $storageApiClient->verifyToken();

        $storageServiceStub = $this->getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects(self::any())
            ->method("getClient")
            ->will(self::returnValue($this->getSapiStub($encrypt)));
        $storageServiceStub->expects(self::any())
            ->method("getTokenData")
            ->will(self::returnValue($tokenData));

        /** @var StorageApiService $storageServiceStub */
        return $storageServiceStub;
    }

    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    public function setUp()
    {
        $this->encryptorFactory = new ObjectEncryptorFactory(
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            hash('sha256', uniqid()),
            hash('sha256', uniqid()),
            Key::createNewRandomKey()->saveToAsciiSafeString(),
            'us-east-1'
        );
    }

    public function testJobFactory()
    {
        $tokenData = $this->getSapiServiceStub()->getTokenData();
        $jobFactory = new JobFactory('docker-bundle', $this->encryptorFactory, $this->getSapiServiceStub());
        $command = uniqid();
        $param = uniqid();
        $lock = uniqid();

        $job = $jobFactory->create($command, ['configData' => $param, 'component' => 'docker-dummy-test'], $lock);

        self::assertEquals($command, $job->getCommand());
        self::assertEquals($lock, $job->getLockName());
        self::assertEquals(['configData' => $param, 'component' => 'docker-dummy-test'], $job->getParams());
        self::assertArrayHasKey('id', $job->getProject());
        self::assertEquals($tokenData['owner']['id'], $job->getProject()['id']);
        self::assertArrayHasKey('name', $job->getProject());
        self::assertEquals($tokenData['owner']['name'], $job->getProject()['name']);
        self::assertArrayHasKey('id', $job->getToken());
        self::assertEquals($tokenData['id'], $job->getToken()['id']);
        self::assertArrayHasKey('description', $job->getToken());
        self::assertEquals($tokenData['description'], $job->getToken()['description']);
        self::assertArrayHasKey('token', $job->getToken());
        self::assertEquals($tokenData['token'], $this->encryptorFactory->getEncryptor()->decrypt($job->getToken()['token']));
    }

    public function testJobEncryptFlagSandbox()
    {
        $jobFactory = new JobFactory('docker-bundle', $this->encryptorFactory, $this->getSapiServiceStub());
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

        $job = $jobFactory->create($command, $this->encryptorFactory->getEncryptor()->encrypt($param), $lock);
        self::assertFalse($job->isEncrypted());
        self::assertEquals($command, $job->getCommand());
        self::assertEquals($lock, $job->getLockName());
        self::assertEquals("KBC::Encrypted==", substr($job->getRawParams()["configData"]["#key2"], 0, 16));
        self::assertEquals("KBC::Encrypted==", substr($job->getParams()["configData"]["#key2"], 0, 16));
    }

    public function testJobEncryptFlagNonEncryptComponent()
    {
        $jobFactory = new JobFactory('docker-bundle', $this->encryptorFactory, $this->getSapiServiceStub(false));
        $command = uniqid();
        $param = [
            "configData" => [
                "key1" => "value1",
                "#key2" => "value2"
            ],
            "component" => "docker-dummy-test"
        ];
        $lock = uniqid();

        $job = $jobFactory->create($command, $this->encryptorFactory->getEncryptor()->encrypt($param), $lock);
        self::assertFalse($job->isEncrypted());
        self::assertEquals($command, $job->getCommand());
        self::assertEquals($lock, $job->getLockName());
        self::assertEquals("KBC::Encrypted==", substr($job->getRawParams()["configData"]["#key2"], 0, 16));
        self::assertEquals("KBC::Encrypted==", substr($job->getParams()["configData"]["#key2"], 0, 16));
    }

    public function testJobEncryptFlagEncryptComponent()
    {
        $jobFactory = new JobFactory('docker-bundle', $this->encryptorFactory, $this->getSapiServiceStub());
        $command = uniqid();
        $param = [
            "configData" => [
                "key1" => "value1",
                "#key2" => "value2"
            ],
            "component" => "docker-dummy-test"
        ];
        $lock = uniqid();

        $job = $jobFactory->create($command, $this->encryptorFactory->getEncryptor()->encrypt($param), $lock);
        self::assertTrue($job->isEncrypted());
        self::assertEquals($command, $job->getCommand());
        self::assertEquals($lock, $job->getLockName());
        self::assertEquals("KBC::Encrypted==", substr($job->getRawParams()["configData"]["#key2"], 0, 16));
        self::assertEquals("value2", $job->getParams()["configData"]["#key2"]);
    }
}
