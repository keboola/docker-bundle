<?php

namespace Keboola\DockerBundle\Tests\Controller;

use Keboola\DockerBundle\Controller\ActionController;
use Keboola\Syrup\Service\ObjectEncryptor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

class ActionControllerTest extends WebTestCase
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
    }

    protected function getStorageServiceStubDummy($encrypt = false)
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
            'components' => array(
                0 => array(
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
                        'definition' => array(
                            'type' => 'dockerhub',
                            'uri' => 'keboola/docker-dummy-test',
                        ),
                        'synchronous_actions' => ['test'],
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

    protected function getStorageServiceStubDcaPython()
    {
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
            'components' => array(
                0 => array (
                    'id' => 'dca-custom-science-python',
                    'type' => 'application',
                    'name' => 'Custom science Python',
                    'description' => 'Custom science Python',
                    'longDescription' => null,
                    'hasUI' => false,
                    'hasRun' => false,
                    'ico32' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/dca-custom-science-python-32-1.png',
                    'ico64' => 'https://d3iz2gfan5zufq.cloudfront.net/images/cloud-services/dca-custom-science-python-64-1.png',
                    'data' => array (
                        'definition' => array (
                            'type' => 'builder',
                            'uri' => 'quay.io/keboola/docker-custom-python:1.1.0',
                            'build_options' => array (
                                'repository' => array (
                                    'uri' => '',
                                    'type' => 'git',
                                ),
                                'commands' => array (
                                    0 => 'git clone -b {{version}} --depth 1 {{repository}} /home/ || (echo "KBC::USER_ERR:Cannot access the Git repository {{repository}}, please verify its URL, credentials and version.KBC::USER_ERR" && exit 1)',
                                ),
                                'parameters' => array (
                                    0 => array (
                                        'name' => 'version',
                                        'type' => 'string',
                                    ),
                                    1 => array (
                                        'name' => 'repository',
                                        'type' => 'string',
                                    ),
                                    2 => array (
                                        'name' => 'username',
                                        'type' => 'string',
                                        'required' => false,
                                    ),
                                    3 => array (
                                        'name' => '#password',
                                        'type' => 'string',
                                        'required' => false,
                                    ),
                                ),
                                'entry_point' => 'python /home/main.py',
                            ),
                        ),
                        'process_timeout' => 21600,
                        'memory' => '8192m',
                        'configuration_format' => 'json',
                        'synchronous_actions' => ['test', 'run', 'timeout', 'invalidjson', 'noresponse', 'usererror', 'apperror', 'decrypt'],
                        ),
                    'flags' => ['encrypt'],
                    'uri' => 'https://syrup.keboola.com/docker/dca-custom-science-python',
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
     * @expectedException \Symfony\Component\HttpKernel\Exception\HttpException
     * @expectedExceptionMessage Component 'docker-dummy-test-invalid' not found
     */
    public function testNonExistingComponent()
    {
        $content = '
        {
            "configData": {
                "something": "else"
            }
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-dummy-test-invalid",
            "action" => "somethingelse"
        ];
        $request = Request::create("/docker/docker-dummy-test-invalid/action/somethingelse", 'POST', $parameters, [], [], $server, $content);

        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDummy(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Symfony\Component\HttpKernel\Exception\HttpException
     * @expectedExceptionMessage Action 'somethingelse' not found
     */
    public function testNonExistingAction()
    {
        $content = '
        {
            "configData": {
                "something": "else"
            }
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-dummy-test",
            "action" => "somethingelse"
        ];
        $request = Request::create("/docker/docker-dummy-test/action/somethingelse", 'POST', $parameters, [], [], $server, $content);


        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDummy(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Symfony\Component\HttpKernel\Exception\HttpException
     * @expectedExceptionMessage Attribute 'configData' missing in request body
     */
    public function testConfigDataMissing()
    {
        $content = '
        {
            "config": {
                "something": "else"
            }
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN
        ];
        $parameters = [
            "component" => "docker-dummy-test",
            "action" => "test"
        ];
        $request = Request::create("/docker/docker-dummy-test/action/test", 'POST', $parameters, [], [], $server, $content);


        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDummy(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }


    public function prepareRequest($method, $parameters = null)
    {
        $content = '
        {
            "configData": {
                "parameters": ' . ($parameters ? json_encode($parameters) : '{}') . '
                ,
                "runtime": {
                    "repository": "https://github.com/keboola/docker-actions-test",
                    "version": "0.0.6"            
                }
            }
        }';
        $server = [
            'HTTP_X-StorageApi-Token' => STORAGE_API_TOKEN,
            'HTTP_Content-Type' => 'application/json'
        ];
        $parameters = [
            "component" => "dca-custom-science-python",
            "action" => $method
        ];
        return Request::create("/docker/dca-custom-science-python/action/{$method}", 'POST', $parameters, [], [], $server, $content);
    }

    public function testActionTest()
    {
        $request = $this->prepareRequest('test');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"test":"test"}', $response->getContent());
    }

    /**
     * @expectedException \Symfony\Component\HttpKernel\Exception\HttpException
     * @expectedExceptionMessage Action 'run' not allowed
     */

    public function testActionRun()
    {
        $request = $this->prepareRequest('run');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }


    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage Running container exceeded the timeout of 30 seconds.
     */
    public function testTimeout()
    {
        $request = $this->prepareRequest('timeout');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage user error
     */
    public function testUserException()
    {
        $request = $this->prepareRequest('usererror');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\ApplicationException
     * @expectedExceptionMessageRegExp /Application error/
     */
    public function testAppException()
    {
        $request = $this->prepareRequest('apperror');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);

    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage Decoding JSON response from component failed
     */
    public function testInvalidJSONRepsonse()
    {
        $request = $this->prepareRequest('invalidjson');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage No response from component
     */
    public function testNoResponse()
    {
        $request = $this->prepareRequest('noresponse');
        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer(self::$container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }

    public function testDecryptSuccess()
    {
        $container = self::$container;

        /**
         * @var $encryptor ObjectEncryptor
         */
        $encryptor = $container->get('syrup.object_encryptor');
        $encryptedPassword = $encryptor->encrypt('password');
        $request = $this->prepareRequest('decrypt', ["#password" => $encryptedPassword]);

        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"password":"password"}', $response->getContent());
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage failed
     */
    public function testDecryptMismatch()
    {
        $container = self::$container;

        /**
         * @var $encryptor ObjectEncryptor
         */
        $encryptor = $container->get('syrup.object_encryptor');
        $encryptedPassword = $encryptor->encrypt('mismatch');
        $request = $this->prepareRequest('decrypt', ["#password" => $encryptedPassword]);

        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);

    }

    public function testDecryptNonEncrypted()
    {
        $request = $this->prepareRequest('decrypt', ["#password" => 'password']);

        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $response = $ctrl->processAction($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('{"password":"password"}', $response->getContent());

    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage failed
     */
    public function testDecryptNonEncryptedMismatch()
    {
        $request = $this->prepareRequest('decrypt', ["#password" => 'mismatch']);

        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);

    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage failed
     */
    public function testDecryptNonEncryptedMismatchTotallyWeird()
    {
        $privateKey = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAyobHg2VSUSwUIPbdx8DY5/0f7Qj0nz24lQnLlN0YjA/ac3Oe\nbKy+VmIoURGXt5cYGpxNLBnDeJyUI+sV9Y5eUHqK2HzN8CY+Z9Lg1Q+Yi5xNmv5c\nkR4E0t4KbGLC5/M1d4nxSSoR7vLz59CX4Ant6E+BfMkmzVxOpWDWkvstSTV/8fcf\nPMFdBSxzNSLIpLcUDevOrcIUuN4UwJhl4c1q9WkpU904vkZZ+W2Xs8MwCh+HkA2I\n2FgQ+sFrEKmNzM4dROTJx31TRFDK4SJv4O7b6s8t2BFrXcIyZTnQ97JXQ4cAKW3w\nK+M0/zRZNzr3F4mtvHncFGLr1i8J9WH+h47TywIDAQABAoIBAG9NTP6AQ5IKqHFJ\nWq8547rzGIWbQ1z0famivXhtXd0zpTmH1Awjj2NIBKIxCfFCn2OYfKz857k0TBHF\nU8ck295cykuZo1AUpH1InnlZXdt0Jg5FNjgmiD4e+xl/2V/CAKNWcv1jmoF4keTX\ndXAR5OakMySSI7n+vdYTdzlFwyiUvLbVRyVYgQhmu0u/iDJKQi1gh2Lt3m5iNryC\n0F0OVpd88gUTdhXKtVIVtNxkhcuHoKJ3grqFvI18xasYt5CpgSg7Wqsu6JWYVyoV\n1fNEKZ1D+QMC0rIjCSphJLDcoOBBafDglZoIfarTzyY9PCHxtlu7uwM4v98dy5SA\n41zhaYkCgYEA8MIrtfwV9q4EumTgN1lF7ilZd9Oedy4kiPBRcoVfGlXd+LoK6oi2\nyWF1U+gNAZl/BHUUITJE/i5+p4MaFStFx3Keu407UlmaU6yljM7cE1cW9RS/cFbv\nsTTRMfnNufDOx9CbJPCvUkSNgTNZiv1/hbky5pmeFk+ZkTwUcSSukvcCgYEA11kB\nYWa2dO0dwk5aUnL+T9L3wvGOrx4ZeLeNbpiivoZJOe0UDRQ9ua9eSjhvraICOKTT\n/tDzyNjjk2J4ccpm0s5W5QGBYpS3cO0E9SUXychFKqIDtHffftcwoCvvTnTQPi/+\nJAKEhnfbVbGyrdU82pBEFtRZf8zPlGKVwsuo/M0CgYEA0tcoknHWBjZ1O4q19KLA\npAYgLNjtUK/fHPFgUmtMUvLZtkWu45+ge5FWv4lbQoha/NtPKpcsZnDvR+F/CQTh\nUf4l1lejmMWRai+qtzo87s748t4dnNL1i/mWLi72pByn6cLc6yfAUcppJbmDdD31\n3HTIh7wF/sHs2YyE1mTqYRcCgYA58zWv5FgNNxHfC/66WT+ec4NA7ogbD9qC5cIl\nlOWWp8Rk1iujKWNC6LJS/sTu0L4QSCrUU56G2fbD3qfS10i8SdKQZctPn/2NYfsH\njSfNoRsb0eV1VxzJoVbwg2IulrjDQ178icDn/rEDaoJOzSdHGbN5AUPkZFUn9S+f\n7/ZVsQKBgFJCQP74gZVudfDKj8k+J4ivTMR0ESZC35O0Yu3eUwKejHs24CNdIeWg\nuOdxDShFac010Y8HPY46d9AASjmIxIM0t8QdMaibL+TUJZgCMq6/Agay1ucrLAxo\nl58J2aHb+6s3RLRTe1i3vk505wdqzUbBHQGckaDiILPXy63KcIMg\n-----END RSA PRIVATE KEY-----\n";
        $request = $this->prepareRequest('decrypt', ["#password" => $privateKey]);

        $container = self::$container;
        $container->set("syrup.storage_api", $this->getStorageServiceStubDcaPython(true));
        $container->get('request_stack')->push($request);

        $ctrl = new ActionController();
        $ctrl->setContainer($container);
        $ctrl->preExecute($request);
        $ctrl->processAction($request);
    }
}
