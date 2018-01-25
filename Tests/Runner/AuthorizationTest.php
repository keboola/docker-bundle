<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\OAuthV2Api\Credentials;
use Keboola\OAuthV2Api\Exception\RequestException;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;

class AuthorizationTest extends \PHPUnit_Framework_TestCase
{
    public function testOauthDecrypt()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');

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
        $oauthResponse = $encryptorFactory->getEncryptor()->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'whatever')
            ->will($this->returnValue($oauthResponse));
        $config = ['oauth_api' => ['id' => 'whatever']];

        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', false);
        $this->assertEquals(
            $credentials,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthDecryptVersions()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');

        $oauthClientStub2 = self::getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $credentials2 = [
            'id' => 'test-credential-1',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            '#appSecret' => '654321',
        ];
        $oauthResponse2 = $encryptorFactory->getEncryptor()->encrypt($credentials2);
        $oauthClientStub2->expects($this->once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'whatever')
            ->will($this->returnValue($oauthResponse2));
        $oauthClientStub3 = self::getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $credentials3 = [
            'id' => 'test-credentials-2',
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"xyz"}',
            'appKey' => '123456',
            '#appSecret' => 'abcdef',
        ];
        $oauthResponse3 = $encryptorFactory->getEncryptor()->encrypt($credentials3);
        $oauthClientStub3->expects($this->once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'whatever')
            ->will($this->returnValue($oauthResponse3));

        /** @var Credentials $oauthClientStub2 */
        /** @var Credentials $oauthClientStub3 */
        $config = ['oauth_api' => ['id' => 'whatever', 'version' => '3']];
        $auth = new Authorization($oauthClientStub2, $oauthClientStub3, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', false);
        self::assertEquals(
            $credentials3,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
        $config = ['oauth_api' => ['id' => 'whatever', 'version' => '2']];
        $auth = new Authorization($oauthClientStub2, $oauthClientStub3, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', false);
        self::assertEquals(
            $credentials2,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthDecryptSandboxed()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');

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
        $oauthResponse = $encryptorFactory->getEncryptor()->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'whatever')
            ->will($this->returnValue($oauthResponse));
        $config = ['oauth_api' => ['id' => 'whatever']];

        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', true);
        $this->assertEquals(
            $oauthResponse,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthConfigDecrypt()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');

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
        $oauthResponse = $encryptorFactory->getEncryptor()->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will($this->returnValue($oauthResponse));
        $config = ["authorization" => ["oauth_api" => ["id" => "test-credentials-45"]]];

        $temp = new Temp();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', false);
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
                    'version' => 2
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
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');

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
        $oauthResponse = $encryptorFactory->getEncryptor()->encrypt($credentials);
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will($this->returnValue($oauthResponse));
        $config = ["authorization" => ["oauth_api" => ["id" => "test-credentials-45"]]];

        $temp = new Temp();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', true);
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
                    'version' => 2
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

    public function testOauthInjected()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');

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
        $config = ['oauth_api' => ['credentials' => $credentials]];

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', false);
        $this->assertEquals(
            $credentials,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthUserError()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');
        $config = ['oauth_api' => ['id' => 'test-credentials-45']];

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will(self::throwException(new RequestException("OAuth API error: No data found for api: keboola.docker-demo", 400)));
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', false);
        try {
            $auth->getAuthorization($config);
            self::fail("Must raise UserException");
        } catch (UserException $e) {
            $this->assertContains('No data found for api', $e->getMessage());
        }
    }

    public function testOauthApplicationError()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');
        $config = ['oauth_api' => ['id' => 'test-credentials-45']];

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $oauthClientStub->expects($this->once())
            ->method("getDetail")
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will(self::throwException(new RequestException("Internal Server Error", 500)));
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', false);
        try {
            $auth->getAuthorization($config);
            self::fail("Must raise ApplicationException");
        } catch (ApplicationException $e) {
            $this->assertContains('Internal Server Error', $e->getMessage());
        }
    }

    public function testOauthInjectedSandboxed()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');
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
        $config = ['oauth_api' => ['credentials' => $encryptorFactory->getEncryptor()->encrypt($credentials)]];

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', true);
        $this->assertEquals(
            $config,
            $auth->getAuthorization($config)
        );
    }

    public function testOauthInjectedConfigDecrypt()
    {
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');

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
        $config = ["authorization" => ["oauth_api" => ["credentials" => $credentials]]];

        $temp = new Temp();
        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', false);
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
                    'version' => 2
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
        $encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $encryptorFactory->setComponentId('keboola.docker-demo');

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
        $config = ["authorization" => ["oauth_api" => ["credentials" => $credentials]]];

        $temp = new Temp();
        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo', true);
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
                    'version' => 2
                ],
            ],
            'image_parameters' => [
                'fooBar' => 'baz',
            ],
            'action' => 'run',
        ];
        $this->assertEquals($sampleData, $data);
    }
}
