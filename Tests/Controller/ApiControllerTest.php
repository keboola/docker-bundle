<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\DockerBundle\Controller\ApiController;
use Keboola\ObjectEncryptor\Legacy\Wrapper\BaseWrapper;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentWrapper as LegacyComponentWrapper;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\ObjectEncryptor\Wrapper\ComponentWrapper;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Job\Metadata\JobInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class ApiControllerTest extends WebTestCase
{
    /**
     * @var ContainerInterface
     */
    private static $container;

    public function setUp()
    {
        parent::setUp();

        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);
    }

    protected function getStorageServiceStub($encrypt = false)
    {
        $flags = [];
        if ($encrypt) {
            $flags = ["encrypt"];
        }
        $storageServiceStub = $this->getMockBuilder("\\Keboola\\DockerBundle\\Service\\StorageApiService")
            ->disableOriginalConstructor()
            ->getMock();
        $storageClientStub = $this->getMockBuilder("\\Keboola\\StorageApi\\Client")
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->atLeastOnce())
            ->method("getClient")
            ->will($this->returnValue($storageClientStub));

        // mock client to return image data
        $indexActionValue = [
            'components' =>
                [
                    0 =>
                        [
                            'id' => 'docker-dummy-test',
                            'type' => 'other',
                            'name' => 'Docker Config Dump',
                            'description' => 'Testing Docker',
                            'longDescription' => null,
                            'hasUI' => false,
                            'hasRun' => true,
                            'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-32-1.png',
                            'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/docker-demo-64-1.png',
                            'data' => [
                                'definition' =>
                                    [
                                        'type' => 'dockerhub',
                                        'uri' => 'keboola/docker-dummy-test',
                                    ],
                            ],
                            'flags' => $flags,
                            'uri' => 'https://syrup.keboola.com/docker/docker-dummy-test',
                        ]
                ]
        ];

        $storageClientStub->expects($this->any())
            ->method("indexAction")
            ->will($this->returnValue($indexActionValue));
        $storageClientStub->expects($this->any())
            ->method("verifyToken")
            ->will($this->returnValue(["owner" => ["id" => "123", "features" => []]]));

        return $storageServiceStub;
    }

    public function testRun()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals(202, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->assertEquals('waiting', $response['status']);
    }

    public function testRunConfigRow()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy-with-rows","row":"dummy-row"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('waiting', $response['status']);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }

    public function testRunTag()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run/tag/1.1.0',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('waiting', $response['status']);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }

    public function testDebug()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/debug',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('waiting', $response['status']);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
    }

    public function testInvalidComponentDebug()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/invalid-component/debug',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Component \'invalid-component\' not found.', $response['message']);
    }

    public function testInvalidComponentRun()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/invalid-component/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Component \'invalid-component\' not found.', $response['message']);
    }

    public function testInvalidBody1()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertArrayHasKey('message', $response);
        $this->assertEquals('Specify \'config\' or \'configData\'.', $response['message']);
    }

    public function testRunConfigData()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{
                "configData": {
                    "foo": "bar"
                }
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('waiting', $response['status']);
    }

    public function testBodyOverload()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{
                "config": "dummy",
                "configData": {
                    "foo": "bar"
                }
            }'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(202, $client->getResponse()->getStatusCode());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('waiting', $response['status']);
    }

    public function testBodyEncrypt()
    {
        $jobMapperMock = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $jobMapperMock->expects($this->once())
            ->method('create')
            ->with($this->callback(function (JobInterface $job) {
                $this->assertArrayHasKey('#foo', $job->getData()['params']['configData']);
                $this->assertStringStartsWith('KBC::Encrypted==', $job->getData()['params']['configData']['#foo']);
                $this->assertEquals('bar', $job->getParams()['configData']['#foo']);
                return "1234"; // not used
            }))
            ->willReturn('12345');
        self::$container->set('syrup.elasticsearch.current_component_job_mapper', $jobMapperMock);

        $request = Request::create(
            "/docker/docker-config-encrypt-verify/run",
            'POST',
            $parameters = [
                "component" => "docker-config-encrypt-verify",
                "action" => 'run'
            ],
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{
                "configData": {
                    "#foo": "bar"
                }
            }'
        );
        self::$container->get('request_stack')->push($request);

        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->runAction($request);

        $this->assertEquals(202, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('status', $data);
        $this->assertEquals('waiting', $data['status']);
        $this->assertStringStartsWith('http', $data['url']);
        $this->assertEquals('12345', $data['id']);
    }

    public function testBodyEncrypted()
    {
        $jobMapperMock = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $jobMapperMock->expects($this->atLeastOnce())
            ->method('create')
            ->with($this->callback(function (JobInterface $job) {
                $this->assertArrayHasKey('#foo', $job->getData()['params']['configData']);
                $this->assertStringStartsWith('KBC::ComponentSecure::', $job->getData()['params']['configData']['#foo']);
                $this->assertEquals('bar', $job->getParams()['configData']['#foo']);
                return '1234'; // not used
            }))
            ->willReturn('12345');
        self::$container->set('syrup.elasticsearch.current_component_job_mapper', $jobMapperMock);
        /** @var $encryptorFactory ObjectEncryptorFactory */
        $encryptorFactory = clone self::$container->get('docker_bundle.object_encryptor_factory');
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encryptorFactory->setStackId(parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST));
        $encrypted = $encryptorFactory->getEncryptor()->encrypt('bar', ComponentWrapper::class);

        $request = Request::create(
            "/docker/docker-config-encrypt-verify/run",
            'POST',
            $parameters = [
                "component" => "docker-config-encrypt-verify",
                "action" => 'run'
            ],
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{
                "configData": {
                    "#foo": "' . $encrypted . '"
                }
            }'
        );
        self::$container->get('request_stack')->push($request);

        $ctrl = new ApiController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->runAction($request);

        $this->assertEquals(202, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('status', $data);
        $this->assertEquals('waiting', $data['status']);
        $this->assertStringStartsWith('http', $data['url']);
        $this->assertEquals('12345', $data['id']);
    }

    public function testMigrateNoMigration()
    {
        $data = ['a' => 'b', 'c' => ['d' => 'not-secret']];
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/migrate?componentId=docker-config-encrypt-verify&projectId=123',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN, 'CONTENT_TYPE' => 'application/json'],
            json_encode($data)
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertEquals(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertEquals($data, $response);
    }

    public function testMigrateNoContentType()
    {
        $data = ['a' => 'b', 'c' => ['d' => 'not-secret']];
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/migrate?componentId=docker-config-encrypt-verify&projectId=123',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            json_encode($data)
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertEquals(400, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertStringStartsWith(
            'Incorrect Content-Type.',
            json_decode($client->getResponse()->getContent(), true)['message']
        );
    }

    public function testMigrateBase()
    {
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get('docker_bundle.object_encryptor_factory');
        $stackId = parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST);
        $encryptorFactory->setStackId($stackId);
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encrypted = $encryptorFactory->getEncryptor()->encrypt('secret', BaseWrapper::class);

        $data = ['a' => 'b', 'c' => ['#d' => $encrypted]];
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/migrate?componentId=docker-config-encrypt-verify&projectId=123',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN, 'CONTENT_TYPE' => 'application/json'],
            json_encode($data)
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertStringStartsWith('KBC::Encrypted==', $encrypted);
        self::assertEquals(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertNotEquals($data, $response);
        self::assertEquals('b', $response['a']);
        self::assertStringStartsWith('KBC::ProjectSecure::', $response['c']['#d']);
    }

    public function testMigrateBaseToComponent()
    {
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get('docker_bundle.object_encryptor_factory');
        $stackId = parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST);
        $encryptorFactory->setStackId($stackId);
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encrypted = $encryptorFactory->getEncryptor()->encrypt('secret', BaseWrapper::class);

        $data = ['a' => 'b', 'c' => ['#d' => $encrypted]];
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/migrate?componentId=docker-config-encrypt-verify',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN, 'CONTENT_TYPE' => 'application/json'],
            json_encode($data)
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertStringStartsWith('KBC::Encrypted==', $encrypted);
        self::assertEquals(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertNotEquals($data, $response);
        self::assertEquals('b', $response['a']);
        self::assertStringStartsWith('KBC::ComponentSecure::', $response['c']['#d']);
    }

    public function testMigrateComponent()
    {
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get('docker_bundle.object_encryptor_factory');
        $stackId = parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST);
        $encryptorFactory->setStackId($stackId);
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encrypted = $encryptorFactory->getEncryptor()->encrypt('secret', LegacyComponentWrapper::class);

        $data = ['a' => 'b', 'c' => ['#d' => $encrypted]];
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/migrate?componentId=docker-config-encrypt-verify&projectId=123',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN, 'CONTENT_TYPE' => 'application/json'],
            json_encode($data)
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertStringStartsWith('KBC::ComponentEncrypted==', $encrypted);
        self::assertEquals(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertNotEquals($data, $response);
        self::assertEquals('b', $response['a']);
        self::assertStringStartsWith('KBC::ProjectSecure::', $response['c']['#d']);
    }

    public function testMigrateComponentProject()
    {
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get('docker_bundle.object_encryptor_factory');
        $stackId = parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST);
        $encryptorFactory->setStackId($stackId);
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encryptorFactory->setProjectId('123');
        $encrypted = $encryptorFactory->getEncryptor()->encrypt('secret', ComponentProjectWrapper::class);

        $data = ['a' => 'b', 'c' => ['#d' => $encrypted]];
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/migrate?componentId=docker-config-encrypt-verify&projectId=123',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN, 'CONTENT_TYPE' => 'application/json'],
            json_encode($data)
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertStringStartsWith('KBC::ComponentProjectEncrypted==', $encrypted);
        self::assertEquals(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertNotEquals($data, $response);
        self::assertEquals('b', $response['a']);
        self::assertStringStartsWith('KBC::ProjectSecure::', $response['c']['#d']);
    }

    public function testMigrateFail()
    {
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get('docker_bundle.object_encryptor_factory');
        $stackId = parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST);
        $encryptorFactory->setStackId($stackId);
        $encryptorFactory->setComponentId('a-different-component');
        $encrypted = $encryptorFactory->getEncryptor()->encrypt('secret', LegacyComponentWrapper::class);

        $data = ['a' => 'b', 'c' => ['#d' => $encrypted]];
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/migrate?componentId=docker-config-encrypt-verify&projectId=123',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN, 'CONTENT_TYPE' => 'application/json'],
            json_encode($data)
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        self::assertStringStartsWith('KBC::ComponentEncrypted==', $encrypted);
        self::assertEquals(400, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertStringStartsWith(
            'Invalid cipher text for key #d Value KBC::ComponentEncrypted==',
            json_decode($client->getResponse()->getContent(), true)['message']
        );
    }

    public function testMigratePlain()
    {
        /** @var ObjectEncryptorFactory $encryptorFactory */
        $encryptorFactory = self::$container->get('docker_bundle.object_encryptor_factory');
        $stackId = parse_url(self::$container->getParameter('storage_api.url'), PHP_URL_HOST);
        $encryptorFactory->setStackId($stackId);
        $encryptorFactory->setComponentId('docker-config-encrypt-verify');
        $encrypted = $encryptorFactory->getEncryptor()->encrypt('secret', LegacyComponentWrapper::class);

        $data = $encrypted;
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/migrate?componentId=docker-config-encrypt-verify&projectId=123',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN, 'CONTENT_TYPE' => 'text/plain'],
            $data
        );
        $response = $client->getResponse()->getContent();
        self::assertStringStartsWith('KBC::ComponentEncrypted==', $encrypted);
        self::assertEquals(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        self::assertNotEquals($data, $response);
        self::assertStringStartsWith('KBC::ProjectSecure::', $response);
    }

    public function testRunEmptyParams()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertEquals('Specify \'config\' or \'configData\'.', json_decode($client->getResponse()->getContent(), true)['message']);
    }

    public function testRunRowOnly()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            '{"row":"my-row"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
        $this->assertEquals('Specify both \'row\' and \'config\'.', json_decode($client->getResponse()->getContent(), true)['message']);
    }
}
