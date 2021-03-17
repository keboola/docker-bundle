<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Elasticsearch\Client;
use Keboola\DockerBundle\Controller\ApiController;
use Keboola\DockerBundle\Tests\Runner\CreateBranchTrait;
use Keboola\ObjectEncryptor\Legacy\Wrapper\BaseWrapper;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentWrapper as LegacyComponentWrapper;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Job\Metadata\JobInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class ApiControllerTest extends WebTestCase
{
    use CreateBranchTrait;

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

    public function testRunBranch()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/branch/dev-123/keboola.r-transformation/run',
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

    public function testRunReadonlyUser()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/keboola.r-transformation/run',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN_READ_ONLY],
            '{"config": "dummy"}'
        );
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals(400, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('User error', $response['error']);
        $this->assertEquals('As a readOnly user you cannot run a job.', $response['message']);
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

    public function testRunBranchTag()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/branch/dev-123/keboola.r-transformation/run/tag/1.1.0',
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

    public function testDebugBranch()
    {
        $client = $this->createClient();
        $client->request(
            'POST',
            '/docker/branch/dev-123/keboola.r-transformation/debug',
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
        $encrypted = $encryptorFactory->getEncryptor()->encrypt('bar', $encryptorFactory->getEncryptor()->getRegisteredComponentWrapperClass());

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

    public function testProjectStats()
    {
        $frameworkClient = $this->createClient();
        /** @var Client $elasticClient */
        $elasticClient = static::$kernel->getContainer()->get('syrup.elasticsearch.client');
        $elasticClient->search();

        // get current project id
        $storageClient = new \Keboola\StorageApi\Client(['url' => STORAGE_API_URL, 'token' => STORAGE_API_TOKEN]);
        $tokenInfo = $storageClient->verifyToken();
        $projectId = $tokenInfo['owner']['id'];

        $jobs = $elasticClient->search([
            'body' => [
                'size' => 1000,
                'query' => [
                    'match' => [
                        'project.id' => $projectId,
                    ],
                ],
            ],
        ]);
        $index = null;
        $type = null;
        foreach ($jobs['hits']['hits'] as $job) {
            $index = $job['_index'];
            $type = $job['_type'];
            $elasticClient->update([
                'index' => $job['_index'],
                'id' => $job['_id'],
                'type' => $job['_type'],
                'body' => [
                    'doc' => [
                        'durationSeconds' => 0,
                    ],
                ],
            ]);
        }
        // assume there was at least one job somewhere
        self::assertNotEmpty($index);

        // insert some jobs to be counted
        $elasticClient->create([
            'index' => $index,
            'type' => $type,
            'body' => [
                'durationSeconds' => 34,
                'endTime' => '2020-08-13T00:01:01+02:00',
                'component' => 'docker',
                'project' => [
                    'id' => $projectId,
                ],
            ],
        ]);
        $elasticClient->create([
            'index' => $index,
            'type' => $type,
            'body' => [
                'durationSeconds' => 1200,
                'component' => 'docker',
                'endTime' => '2020-08-12T15:01:01+02:00',
                'project' => [
                    'id' => $projectId,
                ],
            ],
        ]);

        // insert jobs not to be counted
        $elasticClient->create([
            'index' => $index,
            'type' => $type,
            'body' => [
                'durationSeconds' => 20,
                'component' => 'docker',
                'endTime' => '2020-09-12T15:01:01+02:00',
                'project' => [
                    'id' => 123456,
                ],
            ],
        ]);
        $elasticClient->create([
            'index' => $index,
            'type' => $type,
            'body' => [
                'durationSeconds' => 20,
                'component' => 'orchestrator',
                'endTime' => '2020-08-12T15:01:01+02:00',
                'project' => [
                    'id' => $projectId,
                ],
            ],
        ]);

        // eventual consistency
        sleep(5);

        $frameworkClient->request(
            'GET',
            '/docker/stats/project',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );

        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('jobs', $response, $frameworkClient->getResponse()->getContent());
        $this->assertEquals(['jobs' => ['durationSum' => 1234]], $response);
        $this->assertEquals(200, $frameworkClient->getResponse()->getStatusCode());
    }

    public function testProjectStatsDaily()
    {
        $frameworkClient = $this->createClient();
        /** @var Client $elasticClient */
        $elasticClient = static::$kernel->getContainer()->get('syrup.elasticsearch.client');
        $elasticClient->search();

        // get current project id
        $storageClient = new \Keboola\StorageApi\Client(['url' => STORAGE_API_URL, 'token' => STORAGE_API_TOKEN]);
        $tokenInfo = $storageClient->verifyToken();
        $projectId = $tokenInfo['owner']['id'];

        $jobs = $elasticClient->search([
            'body' => [
                'size' => 1000,
                'query' => [
                    'match' => [
                        'project.id' => $projectId,
                    ],
                ],
            ],
        ]);
        $index = null;
        $type = null;
        foreach ($jobs['hits']['hits'] as $job) {
            $index = $job['_index'];
            $type = $job['_type'];
            $elasticClient->update([
                'index' => $job['_index'],
                'id' => $job['_id'],
                'type' => $job['_type'],
                'body' => [
                    'doc' => [
                        'durationSeconds' => 0,
                    ],
                ],
            ]);
        }
        // assume there was at least one job somewhere
        self::assertNotEmpty($index);

        // insert some jobs to be counted
        $elasticClient->create([
            'index' => $index,
            'type' => $type,
            'body' => [
                'durationSeconds' => 34,
                'component' => 'docker',
                'endTime' => '2020-08-13T00:01:01+02:00',
                'project' => [
                    'id' => $projectId,
                ],
            ],
        ]);
        $elasticClient->create([
            'index' => $index,
            'type' => $type,
            'body' => [
                'durationSeconds' => 1200,
                'component' => 'docker',
                'endTime' => '2020-08-12T15:01:01+02:00',
                'project' => [
                    'id' => $projectId,
                ],
            ],
        ]);
        $elasticClient->create([
            'index' => $index,
            'type' => $type,
            'body' => [
                'durationSeconds' => 560,
                'component' => 'docker',
                'endTime' => '2020-08-09T15:01:01+02:00',
                'project' => [
                    'id' => $projectId,
                ],
            ],
        ]);

        // insert jobs not to be counted
        $elasticClient->create([
            'index' => $index,
            'type' => $type,
            'body' => [
                'durationSeconds' => 20,
                'component' => 'docker',
                'endTime' => '2020-09-12T15:01:01+02:00',
                'project' => [
                    'id' => 123456,
                ],
            ],
        ]);
        $elasticClient->create([
            'index' => $index,
            'type' => $type,
            'body' => [
                'durationSeconds' => 20,
                'component' => 'orchestrator',
                'endTime' => '2020-08-13T00:01:01+02:00',
                'project' => [
                    'id' => $projectId,
                ],
            ],
        ]);

        // eventual consistency TZOFFSET:02:00
        sleep(5);

        // first test TZOFFSET:02:00
        $frameworkClient->request(
            'GET',
            '/docker/stats/project/daily?fromDate=2020-08-12&toDate=2020-08-13&timezoneOffset=%2B02:00',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('jobs', $response, $frameworkClient->getResponse()->getContent());
        $this->assertEquals(
            [
                [
                    'date' => '2020-08-12',
                    'durationSum' => 1200,
                ],
                [
                    'date' => '2020-08-13',
                    'durationSum' => 34,
                ],
            ],
            $response['jobs']
        );
        $this->assertEquals(200, $frameworkClient->getResponse()->getStatusCode());

        // second test TZOFFSET:00:00
        $frameworkClient->request(
            'GET',
            '/docker/stats/project/daily?fromDate=2020-08-12&toDate=2020-08-13&timezoneOffset=%2B00:00',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('jobs', $response, $frameworkClient->getResponse()->getContent());
        $this->assertEquals(
            [
                [
                    'date' => '2020-08-12',
                    'durationSum' => 1234,
                ]
            ],
            $response['jobs']
        );
        $this->assertEquals(200, $frameworkClient->getResponse()->getStatusCode());

        // third test - bigger range
        $frameworkClient->request(
            'GET',
            '/docker/stats/project/daily?fromDate=2020-08-09&toDate=2020-08-13&timezoneOffset=%2B00:00',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('jobs', $response, $frameworkClient->getResponse()->getContent());
        $this->assertEquals(
            [
                [
                    'date' => '2020-08-09',
                    'durationSum' => 560,
                ],
                [
                    'date' => '2020-08-10',
                    'durationSum' => 0,
                ],
                [
                    'date' => '2020-08-11',
                    'durationSum' => 0,
                ],
                [
                    'date' => '2020-08-12',
                    'durationSum' => 1234,
                ]
            ],
            $response['jobs']
        );
        $this->assertEquals(200, $frameworkClient->getResponse()->getStatusCode());

        // fourth test - negative range
        $frameworkClient->request(
            'GET',
            '/docker/stats/project/daily?fromDate=2022-08-09&toDate=2020-08-13&timezoneOffset=%2B00:00',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('jobs', $response, $frameworkClient->getResponse()->getContent());
        $this->assertEquals([], $response['jobs']);
        $this->assertEquals(200, $frameworkClient->getResponse()->getStatusCode());
    }

    public function testProjectStatsDailyMissingFromDate()
    {
        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'GET',
            '/docker/stats/project/daily',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $response, $frameworkClient->getResponse()->getContent());
        $this->assertEquals('Missing or invalid "fromDate" query parameter.', $response['message']);
        $this->assertEquals(400, $frameworkClient->getResponse()->getStatusCode());
    }

    public function testProjectStatsDailyInvalidFromDate()
    {
        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'GET',
            '/docker/stats/project/daily?'.http_build_query([
                'fromDate' => '20200501',
                'toDate' => '2020-05-07',
                'timezoneOffset' => '+02:00',
            ]),
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        self::assertArrayHasKey('message', $response, $frameworkClient->getResponse()->getContent());
        self::assertEquals('Missing or invalid "fromDate" query parameter.', $response['message']);
        self::assertEquals(400, $frameworkClient->getResponse()->getStatusCode());
    }

    public function testProjectStatsDailyMissingToDate()
    {
        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'GET',
            '/docker/stats/project/daily?fromDate=2022-08-01',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $response, $frameworkClient->getResponse()->getContent());
        $this->assertEquals('Missing or invalid "toDate" query parameter.', $response['message']);
        $this->assertEquals(400, $frameworkClient->getResponse()->getStatusCode());
    }

    public function testProjectStatsDailyInvalidToDate()
    {
        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'GET',
            '/docker/stats/project/daily?'.http_build_query([
                'fromDate' => '2020-05-01',
                'toDate' => '20200507',
                'timezoneOffset' => '+02:00',
            ]),
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        self::assertArrayHasKey('message', $response, $frameworkClient->getResponse()->getContent());
        self::assertEquals('Missing or invalid "toDate" query parameter.', $response['message']);
        self::assertEquals(400, $frameworkClient->getResponse()->getStatusCode());
    }

    public function testProjectStatsDailyMissingTimezone()
    {
        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'GET',
            '/docker/stats/project/daily?fromDate=2022-08-01&toDate=2020-01-01',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $response, $frameworkClient->getResponse()->getContent());
        $this->assertEquals('Missing or invalid "timezoneOffset" query parameter.', $response['message']);
        $this->assertEquals(400, $frameworkClient->getResponse()->getStatusCode());
    }

    public function testProjectStatsDailyInvalidTimezone()
    {
        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'GET',
            '/docker/stats/project/daily?'.http_build_query([
                'fromDate' => '2020-05-01',
                'toDate' => '2020-05-07',
                'timezoneOffset' => '7',
            ]),
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN]
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        self::assertArrayHasKey('message', $response, $frameworkClient->getResponse()->getContent());
        self::assertEquals('Missing or invalid "timezoneOffset" query parameter.', $response['message']);
        self::assertEquals(400, $frameworkClient->getResponse()->getStatusCode());
    }

    private function createSharedConfigurations()
    {
        $storageClient = new \Keboola\StorageApi\Client(['url' => STORAGE_API_URL, 'token' => STORAGE_API_TOKEN]);
        $components = new Components($storageClient);
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.variables');
        $configuration->setName(uniqid('test-resolve-v-'));
        $configuration->setConfiguration(
            ['variables' => [['name' => 'firstvar', 'type' => 'string'], ['name' => 'secondvar', 'type' => 'string']]]
        );
        $variablesId = $components->addConfiguration($configuration)['id'];
        $configuration->setConfigurationId($variablesId);
        $row = new ConfigurationRow($configuration);
        $row->setConfiguration(
            ['values' => [['name' => 'firstvar', 'value' => 'batman'], ['name' => 'secondvar', 'value' => 'watman']]]
        );
        $variableValuesId = $components->addConfigurationRow($row)['id'];

        $configuration = new Configuration();
        $configuration->setComponentId('keboola.shared-code');
        $configuration->setName(uniqid('test-resolve-sc-'));
        $sharedCodeId = $components->addConfiguration($configuration)['id'];
        $configuration->setConfigurationId($sharedCodeId);
        $row = new ConfigurationRow($configuration);
        $row->setRowId('brainfuck');
        $row->setConfiguration(['code_content' => '++++++++[>++++[>++>+++>+++>+<<<<-]>+>+>->>+[<]<-]>>.>---.+++++++..+++.>>.<-.<.+++.------.--------.>>+.>++.']);
        $sharedCodeRowId = $components->addConfigurationRow($row)['id'];

        return [$variablesId, $variableValuesId, $sharedCodeId, $sharedCodeRowId];
    }

    public function testResolveVariables()
    {
        $storageClient = new \Keboola\StorageApi\Client(['url' => STORAGE_API_URL, 'token' => STORAGE_API_TOKEN]);
        $components = new Components($storageClient);
        list($variablesId, $variableValuesId, $sharedCodeId, $sharedCodeRowId) = $this->createSharedConfigurations();

        $componentId = 'keboola.python-transformation-v2';
        $configuration = new Configuration();
        $configuration->setComponentId($componentId);
        $configuration->setName(uniqid('test-resolve-'));
        $configuration->setConfiguration(
            [
                'parameters' => ['a' => '{{firstvar}}', 'c' => '{{brainfuck}}', 'd' => '{{secondvar}}'],
                'variables_id' => $variablesId,
                'variables_values_id' => $variableValuesId,
                'shared_code_id' => $sharedCodeId,
                'shared_code_row_ids' => [$sharedCodeRowId]
            ]
        );
        $result = $components->addConfiguration($configuration);
        $configId = $result['id'];
        $configVersion = $result['version'];

        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'POST',
            '/docker/configuration/resolve',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            json_encode([
                'componentId' => $componentId,
                'configId' => $configId,
                'configVersion' => $configVersion,
            ])
        );
        $this->assertEquals(
            200,
            $frameworkClient->getResponse()->getStatusCode(),
            $frameworkClient->getResponse()->getContent()
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $response);
        $this->assertArrayHasKey('version', $response);
        $this->assertArrayHasKey('name', $response);
        // state is always cleared
        $this->assertEquals([], $response['state']);
        $this->assertEquals(
            [
                'parameters' => [
                    'a' => 'batman',
                    'c' => '++++++++[>++++[>++>+++>+++>+<<<<-]>+>+>->>+[<]<-]>>.>---.+++++++..+++.>>.<-.<.+++.------.--------.>>+.>++.',
                    'd' => 'watman',
                ],
                'shared_code_row_ids' => ['brainfuck'],
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'variables_id' => $variablesId,
                'variables_values_id' => $variableValuesId,
                'shared_code_id' => $sharedCodeId,
            ],
            $response['configuration']
        );
        $this->assertEquals([], $response['rows']);
        $components->deleteConfiguration($componentId, $configId);
        $components->deleteConfiguration('keboola.variables', $variablesId);
        $components->deleteConfiguration('keboola.shared-code', $sharedCodeId);
    }

    public function testResolveVariablesBranch()
    {
        $storageClient = new \Keboola\StorageApi\Client(['url' => STORAGE_API_URL, 'token' => STORAGE_API_TOKEN]);
        $components = new Components($storageClient);
        list($variablesId, $variableValuesId, $sharedCodeId, $sharedCodeRowId) = $this->createSharedConfigurations();

        $componentId = 'keboola.python-transformation-v2';
        $configuration = new Configuration();
        $configuration->setComponentId($componentId);
        $configuration->setName(uniqid('test-resolve-'));
        $configuration->setConfiguration(
            [
                'parameters' => ['a' => '{{firstvar}}', 'c' => '{{brainfuck}}', 'd' => '{{secondvar}}'],
                'variables_id' => $variablesId,
                'variables_values_id' => $variableValuesId,
                'shared_code_id' => $sharedCodeId,
                'shared_code_row_ids' => [$sharedCodeRowId]
            ]
        );
        $result = $components->addConfiguration($configuration);
        $configId = $result['id'];

        $clientWrapper = new ClientWrapper(
            new \Keboola\StorageApi\Client([
                'token' => STORAGE_API_TOKEN_MASTER,
                'url' => STORAGE_API_URL,
            ]),
            null,
            new NullLogger()
        );
        $branchId = $this->createBranch($clientWrapper, 'resolve-branch');
        $clientWrapper->setBranchId($branchId);

        // modify the dev branch variable configuration to "dev-bar"
        $components = new Components($clientWrapper->getBranchClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.variables');
        $configuration->setConfigurationId($variablesId);
        $newRow = new ConfigurationRow($configuration);
        $newRow->setRowId($variableValuesId);
        $newRow->setConfiguration(
            ['values' => [['name' => 'firstvar', 'value' => 'dev-batman'], ['name' => 'secondvar', 'value' => 'dev-watman']]]
        );
        $components->updateConfigurationRow($newRow);

        // modify the shared code configuration to "brainbug"
        $components = new Components($clientWrapper->getBranchClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.shared-code');
        $configuration->setConfigurationId($sharedCodeId);
        $newRow = new ConfigurationRow($configuration);
        $newRow->setRowId($sharedCodeRowId);
        $newRow->setConfiguration(['code_content' => 'brainbug']);
        $components->updateConfigurationRow($newRow);

        // modify the dev branch configuration itself
        $components = new Components($clientWrapper->getBranchClient());
        $configuration = new Configuration();
        $configuration->setComponentId($componentId);
        $configuration->setConfigurationId($configId);
        $configuration->setConfiguration(
            [
                'parameters' => ['a' => '{{firstvar}}', 'c' => '{{brainfuck}}', 'dev-e' => '{{secondvar}}'],
                'variables_id' => $variablesId,
                'variables_values_id' => $variableValuesId,
                'shared_code_id' => $sharedCodeId,
                'shared_code_row_ids' => [$sharedCodeRowId]
            ]
        );
        $newVersion = $components->updateConfiguration($configuration)['version'];

        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'POST',
            sprintf('/docker/branch/%s/configuration/resolve', $branchId),
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN_MASTER],
            json_encode([
                'componentId' => $componentId,
                'configId' => $configId,
                'configVersion' => $newVersion,
            ])
        );
        $this->assertEquals(
            200,
            $frameworkClient->getResponse()->getStatusCode(),
            $frameworkClient->getResponse()->getContent()
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $response);
        $this->assertArrayHasKey('version', $response);
        $this->assertArrayHasKey('name', $response);
        // state is always cleared
        $this->assertEquals([], $response['state']);
        $this->assertEquals(
            [
                'parameters' => [
                    'a' => 'dev-batman',
                    'c' => 'brainbug',
                    'dev-e' => 'dev-watman',
                ],
                'shared_code_row_ids' => ['brainfuck'],
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'variables_id' => $variablesId,
                'variables_values_id' => $variableValuesId,
                'shared_code_id' => $sharedCodeId,
            ],
            $response['configuration']
        );
        $this->assertEquals([], $response['rows']);
        $components = new Components($clientWrapper->getBasicClient());
        $components->deleteConfiguration($componentId, $configId);
        $components->deleteConfiguration('keboola.variables', $variablesId);
        $components->deleteConfiguration('keboola.shared-code', $sharedCodeId);
    }

    public function testResolveVariablesInline()
    {
        $storageClient = new \Keboola\StorageApi\Client(['url' => STORAGE_API_URL, 'token' => STORAGE_API_TOKEN]);
        $components = new Components($storageClient);
        list($variablesId, $variableValuesId, $sharedCodeId, $sharedCodeRowId) = $this->createSharedConfigurations();

        $componentId = 'keboola.python-transformation-v2';
        $configuration = new Configuration();
        $configuration->setComponentId($componentId);
        $configuration->setName(uniqid('test-resolve-'));
        $configuration->setConfiguration(
            [
                'parameters' => ['a' => '{{firstvar}}', 'c' => '{{brainfuck}}', 'd' => '{{secondvar}}'],
                'variables_id' => $variablesId,
                'variables_values_id' => $variableValuesId,
                'shared_code_id' => $sharedCodeId,
                'shared_code_row_ids' => [$sharedCodeRowId]
            ]
        );
        $result = $components->addConfiguration($configuration);
        $configId = $result['id'];
        $configVersion = $result['version'];

        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'POST',
            '/docker/configuration/resolve',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            json_encode([
                'componentId' => $componentId,
                'configId' => $configId,
                'configVersion' => $configVersion,
                'variableValuesData' => [
                    'values' => [
                        [
                            'name' => 'firstvar',
                            'value' => 'boo',
                        ],
                        [
                            'name' => 'secondvar',
                            'value' => 'foo',
                        ],
                        [
                            'name' => 'brainfuck',
                            'value' => 'not used',
                        ],
                    ],
                ],
            ])
        );
        $this->assertEquals(
            200,
            $frameworkClient->getResponse()->getStatusCode(),
            $frameworkClient->getResponse()->getContent()
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $response);
        $this->assertArrayHasKey('version', $response);
        $this->assertArrayHasKey('name', $response);
        // state is always cleared
        $this->assertEquals([], $response['state']);
        var_dump($frameworkClient->getResponse()->getContent());
        $this->assertEquals(
            [
                'parameters' => [
                    'a' => 'boo',
                    'c' => '++++++++[>++++[>++>+++>+++>+<<<<-]>+>+>->>+[<]<-]>>.>---.+++++++..+++.>>.<-.<.+++.------.--------.>>+.>++.',
                    'd' => 'foo',
                ],
                'shared_code_row_ids' => ['brainfuck'],
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'variables_id' => $variablesId,
                'variables_values_id' => $variableValuesId,
                'shared_code_id' => $sharedCodeId,
            ],
            $response['configuration']
        );
        $this->assertEquals([], $response['rows']);
        $components->deleteConfiguration($componentId, $configId);
        $components->deleteConfiguration('keboola.variables', $variablesId);
        $components->deleteConfiguration('keboola.shared-code', $sharedCodeId);
    }

    public function testResolveVariablesRows()
    {
        $storageClient = new \Keboola\StorageApi\Client(['url' => STORAGE_API_URL, 'token' => STORAGE_API_TOKEN]);
        $components = new Components($storageClient);
        list($variablesId, $variableValuesId, $sharedCodeId, $sharedCodeRowId) = $this->createSharedConfigurations();

        $componentId = 'keboola.python-transformation-v2';
        $configuration = new Configuration();
        $configuration->setComponentId($componentId);
        $configuration->setName(uniqid('test-resolve-'));
        $configuration->setConfiguration(['parameters' => ['a' => '{{firstvar}}'], 'variables_id' => $variablesId, 'variables_values_id' => $variableValuesId]);
        $result = $components->addConfiguration($configuration);
        $row = new ConfigurationRow($configuration);
        $configId = $result['id'];
        $configuration->setConfigurationId($configId);
        $row->setConfiguration(['parameters' => ['c' => '{{brainfuck}}', 'd' => '{{secondvar}}'], 'shared_code_id' => $sharedCodeId, 'shared_code_row_ids' => [$sharedCodeRowId]]);
        $row->setName(uniqid('test-resolve-'));
        $components->addConfigurationRow($row);
        $result = $components->getConfiguration($componentId, $configId);
        $configVersion = $result['version'];

        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'POST',
            '/docker/configuration/resolve',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            json_encode([
                'componentId' => $componentId,
                'configId' => $configId,
                'configVersion' => $configVersion,
            ])
        );
        $this->assertEquals(
            200,
            $frameworkClient->getResponse()->getStatusCode(),
            $frameworkClient->getResponse()->getContent()
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $response);
        $this->assertArrayHasKey('version', $response);
        $this->assertArrayHasKey('name', $response);
        // state is always cleared
        $this->assertEquals([], $response['state']);
        $this->assertEquals(
            [
                'parameters' => [
                    'a' => '{{firstvar}}',
                ],
                'variables_id' => $variablesId,
                'variables_values_id' => $variableValuesId,
            ],
            $response['configuration']
        );
        $this->assertArrayHasKey('id', $response['rows'][0]);
        $this->assertArrayHasKey('version', $response['rows'][0]);
        $this->assertArrayHasKey('name', $response['rows'][0]);
        // state is always cleared
        $this->assertEquals([], $response['rows'][0]['state']);
        $this->assertEquals(
            [
                'parameters' => [
                    'a' => 'batman',
                    'c' => '++++++++[>++++[>++>+++>+++>+<<<<-]>+>+>->>+[<]<-]>>.>---.+++++++..+++.>>.<-.<.+++.------.--------.>>+.>++.',
                    'd' => 'watman',
                ],
                'shared_code_row_ids' => ['brainfuck'],
                'storage' => [],
                'processors' => [
                    'before' => [],
                    'after' => [],
                ],
                'variables_id' => $variablesId,
                'variables_values_id' => $variableValuesId,
                'shared_code_id' => $sharedCodeId,
            ],
            $response['rows'][0]['configuration']
        );
        $components->deleteConfiguration($componentId, $configId);
        $components->deleteConfiguration('keboola.variables', $variablesId);
        $components->deleteConfiguration('keboola.shared-code', $sharedCodeId);
    }

    public function testResolveVariablesMissingVariables()
    {
        $storageClient = new \Keboola\StorageApi\Client(['url' => STORAGE_API_URL, 'token' => STORAGE_API_TOKEN]);
        $components = new Components($storageClient);
        list($variablesId, $variableValuesId, $sharedCodeId, $sharedCodeRowId) = $this->createSharedConfigurations();

        $componentId = 'keboola.python-transformation-v2';
        $configuration = new Configuration();
        $configuration->setComponentId($componentId);
        $configuration->setName(uniqid('test-resolve-'));
        $configuration->setConfiguration(['parameters' => ['a' => '{{firstvar}}'], 'variables_id' => $variablesId]);
        $result = $components->addConfiguration($configuration);
        $configId = $result['id'];
        $configVersion = $result['version'];

        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'POST',
            '/docker/configuration/resolve',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            json_encode([
                'componentId' => $componentId,
                'configId' => $configId,
                'configVersion' => $configVersion,
            ])
        );
        $this->assertEquals(
            400,
            $frameworkClient->getResponse()->getStatusCode(),
            $frameworkClient->getResponse()->getContent()
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertEquals('User error', $response['error']);
        $this->assertEquals(
            sprintf(
                'No variable values provided for configuration "%s", row "", referencing variables "%s".',
                $configId,
                $variablesId
            ),
            $response['message']
        );

        $components->deleteConfiguration($componentId, $configId);
        $components->deleteConfiguration('keboola.variables', $variablesId);
        $components->deleteConfiguration('keboola.shared-code', $sharedCodeId);
    }

    public function testResolveVariablesMissingArgs()
    {
        $frameworkClient = $this->createClient();
        $frameworkClient->request(
            'POST',
            '/docker/configuration/resolve',
            [],
            [],
            ['HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN],
            json_encode([
            ])
        );
        $this->assertEquals(
            400,
            $frameworkClient->getResponse()->getStatusCode(),
            $frameworkClient->getResponse()->getContent()
        );
        $response = json_decode($frameworkClient->getResponse()->getContent(), true);
        $this->assertEquals('User error', $response['error']);
        $this->assertEquals('Missing "componentId" parameter in request body.', $response['message']);
    }
}
