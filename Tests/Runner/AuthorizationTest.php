<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\OAuthV2Api\Credentials;
use Keboola\Syrup\Encryption\BaseWrapper;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;

class AuthorizationTest extends \PHPUnit_Framework_TestCase
{
    public function testOauthDecrypt()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $oauthResponse = $encryptor->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'whatever')
            ->will($this->returnValue($oauthResponse));
        $config = ['oauth_api' => ['id' => 'whatever']];

        $auth = new Authorization($oauthClientStub, $encryptor, 'keboola.docker-demo', false);
        $this->assertEquals(
            $credentials,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthDecryptSandboxed()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $oauthResponse = $encryptor->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'whatever')
            ->will($this->returnValue($oauthResponse));
        $config = ['oauth_api' => ['id' => 'whatever']];

        $auth = new Authorization($oauthClientStub, $encryptor, 'keboola.docker-demo', true);
        $this->assertEquals(
            $oauthResponse,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthConfigDecrypt()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $oauthResponse = $encryptor->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will($this->returnValue($oauthResponse));
        $config = ["authorization" => ["oauth_api" => ["id" => "test-credentials-45"]]];

        $temp = new Temp();
        $auth = new Authorization($oauthClientStub, $encryptor, 'keboola.docker-demo', false);
        $configFile = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $auth, 'run', 'json');
        $configFile->createConfigFile($config);
        $data = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);
        $sampleData = [
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        'id' => 'test-credentials-45',
                        'authorizedFor' => '',
                        'creator' => [
                            'id' => '3800',
                            'description' => 'ondrej.hlavacek@keboola.com'
                        ],
                        'created' => '2016-02-09 09:47:16',
                        '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
                        'oauthVersion' => '2.0',
                        'appKey' => '123456',
                        '#appSecret' => '654321',
                    ],
                ],
            ],
            'image_parameters' => [
                'fooBar' => 'baz',
            ],
            'action' => 'run',
        ];
        $this->assertEquals($sampleData, $data);
    }

    public function testOauthConfigDecryptSandboxed()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $oauthResponse = $encryptor->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will($this->returnValue($oauthResponse));
        $config = ["authorization" => ["oauth_api" => ["id" => "test-credentials-45"]]];

        $temp = new Temp();
        $auth = new Authorization($oauthClientStub, $encryptor, 'keboola.docker-demo', true);
        $configFile = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $auth, 'run', 'json');
        $configFile->createConfigFile($config);
        $data = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);
        $sampleData = [
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        'id' => 'test-credentials-45',
                        'authorizedFor' => '',
                        'creator' => [
                            'id' => '3800',
                            'description' => 'ondrej.hlavacek@keboola.com'
                        ],
                        'created' => '2016-02-09 09:47:16',
                        '#data' => 'KBC::Encrypted==',
                        'oauthVersion' => '2.0',
                        'appKey' => '123456',
                        '#appSecret' => 'KBC::Encrypted==',
                    ],
                ],
            ],
            'image_parameters' => [
                'fooBar' => 'baz',
            ],
            'action' => 'run',
        ];
        $data['authorization']['oauth_api']['credentials']['#data'] = substr(
            $data['authorization']['oauth_api']['credentials']['#data'],
            0,
            16
        );
        $data['authorization']['oauth_api']['credentials']['#appSecret'] = substr(
            $data['authorization']['oauth_api']['credentials']['#appSecret'],
            0,
            16
        );
        $this->assertEquals($sampleData, $data);
    }

    public function testOauthInjectedDecrypt()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $config = ['oauth_api' => ['credentials' => $encryptor->encrypt($credentials)]];

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $auth = new Authorization($oauthClientStub, $encryptor, 'keboola.docker-demo', false);
        $this->assertEquals(
            $credentials,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }


    public function testOauthInjectedDecryptSandboxed()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $config = ['oauth_api' => ['credentials' => $encryptor->encrypt($credentials)]];

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $auth = new Authorization($oauthClientStub, $encryptor, 'keboola.docker-demo', true);
        $this->assertEquals(
            $config,
            $auth->getAuthorization($config)
        );
    }


    public function testOauthInjectedConfigDecrypt()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $config = ["authorization" => ["oauth_api" => ["credentials" => $encryptor->encrypt($credentials)]]];

        $temp = new Temp();
        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $auth = new Authorization($oauthClientStub, $encryptor, 'keboola.docker-demo', false);
        $configFile = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $auth, 'run', 'json');
        $configFile->createConfigFile($config);
        $data = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);
        $sampleData = [
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        'id' => 'test-credentials-45',
                        'authorizedFor' => '',
                        'creator' => [
                            'id' => '3800',
                            'description' => 'ondrej.hlavacek@keboola.com'
                        ],
                        'created' => '2016-02-09 09:47:16',
                        '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
                        'oauthVersion' => '2.0',
                        'appKey' => '123456',
                        '#appSecret' => '654321',
                    ],
                ],
            ],
            'image_parameters' => [
                'fooBar' => 'baz',
            ],
            'action' => 'run',
        ];
        $this->assertEquals($sampleData, $data);
    }


    public function testOauthInjectedConfigDecryptSandboxed()
    {
        $encryptor = new ObjectEncryptor();
        $wrapper = new ComponentWrapper(md5(uniqid()));
        $wrapper->setComponentId('keboola.docker-demo');
        $encryptor->pushWrapper($wrapper);
        $encryptor->pushWrapper(new BaseWrapper(md5(uniqid())));

        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com'
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $config = ["authorization" => ["oauth_api" => ["credentials" => $encryptor->encrypt($credentials)]]];

        $temp = new Temp();
        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $auth = new Authorization($oauthClientStub, $encryptor, 'keboola.docker-demo', true);
        $configFile = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $auth, 'run', 'json');
        $configFile->createConfigFile($config);
        $data = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);
        $sampleData = [
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        'id' => 'test-credentials-45',
                        'authorizedFor' => '',
                        'creator' => [
                            'id' => '3800',
                            'description' => 'ondrej.hlavacek@keboola.com'
                        ],
                        'created' => '2016-02-09 09:47:16',
                        '#data' => 'KBC::Encrypted==',
                        'oauthVersion' => '2.0',
                        'appKey' => '123456',
                        '#appSecret' => 'KBC::Encrypted==',
                    ],
                ],
            ],
            'image_parameters' => [
                'fooBar' => 'baz',
            ],
            'action' => 'run',
        ];
        $data['authorization']['oauth_api']['credentials']['#data'] = substr(
            $data['authorization']['oauth_api']['credentials']['#data'],
            0,
            16
        );
        $data['authorization']['oauth_api']['credentials']['#appSecret'] = substr(
            $data['authorization']['oauth_api']['credentials']['#appSecret'],
            0,
            16
        );
        $this->assertEquals($sampleData, $data);
    }
}
