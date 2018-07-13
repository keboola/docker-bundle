<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\OAuthV2Api\Credentials;
use Keboola\OAuthV2Api\Exception\RequestException;
use Keboola\Temp\Temp;

class AuthorizationTest extends BaseRunnerTest
{
    public function testOauthDecrypt()
    {
        $encryptorFactory = $this->getEncryptorFactory();
        $encryptorFactory->setComponentId('keboola.docker-demo');
        $oauthClientStub = self::getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com',
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $oauthResponse = $encryptorFactory->getEncryptor()->encrypt($credentials);
        $oauthClientStub->expects(self::once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'whatever')
            ->will(self::returnValue($oauthResponse));
        $config = ['oauth_api' => ['id' => 'whatever']];

        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo');
        self::assertEquals(
            $credentials,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthDecryptVersions()
    {
        $encryptorFactory = $this->getEncryptorFactory();
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
        $oauthClientStub2->expects(self::once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'whatever')
            ->will(self::returnValue($oauthResponse2));
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
        $oauthClientStub3->expects(self::once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'whatever')
            ->will(self::returnValue($oauthResponse3));

        /** @var Credentials $oauthClientStub2 */
        /** @var Credentials $oauthClientStub3 */
        $config = ['oauth_api' => ['id' => 'whatever', 'version' => '3']];
        $auth = new Authorization($oauthClientStub2, $oauthClientStub3, $encryptorFactory->getEncryptor(), 'keboola.docker-demo');
        self::assertEquals(
            $credentials3,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
        $config = ['oauth_api' => ['id' => 'whatever', 'version' => '2']];
        $auth = new Authorization($oauthClientStub2, $oauthClientStub3, $encryptorFactory->getEncryptor(), 'keboola.docker-demo');
        self::assertEquals(
            $credentials2,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthConfigDecrypt()
    {
        $encryptorFactory = $this->getEncryptorFactory();
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
        $oauthClientStub->expects(self::once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will(self::returnValue($oauthResponse));
        $config = ['authorization' => ['oauth_api' => ['id' => 'test-credentials-45']]];

        $temp = new Temp();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo');
        $configFile = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $auth, 'run', 'json');
        $configFile->createConfigFile($config, new OutputFilter());
        $data = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);
        $sampleData = [
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        'id' => 'test-credentials-45',
                        'authorizedFor' => '',
                        'creator' => [
                            'id' => '3800',
                            'description' => 'ondrej.hlavacek@keboola.com',
                        ],
                        'created' => '2016-02-09 09:47:16',
                        '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
                        'oauthVersion' => '2.0',
                        'appKey' => '123456',
                        '#appSecret' => '654321',
                    ],
                    'version' => 2,
                ],
            ],
            'image_parameters' => [
                'fooBar' => 'baz',
            ],
            'action' => 'run',
        ];
        self::assertEquals($sampleData, $data);
    }

    public function testOauthInjected()
    {
        $encryptorFactory = $this->getEncryptorFactory();
        $encryptorFactory->setComponentId('keboola.docker-demo');
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com',
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $config = ['oauth_api' => ['credentials' => $credentials]];

        $oauthClientStub = self::getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo');
        self::assertEquals(
            $credentials,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthUserError()
    {
        $encryptorFactory = $this->getEncryptorFactory();
        $encryptorFactory->setComponentId('keboola.docker-demo');
        $config = ['oauth_api' => ['id' => 'test-credentials-45']];

        $oauthClientStub = self::getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $oauthClientStub->expects(self::once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will(self::throwException(new RequestException('OAuth API error: No data found for api: keboola.docker-demo', 400)));
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo');
        self::expectException(UserException::class);
        self::expectExceptionMessage('No data found for api');
        $auth->getAuthorization($config);
    }

    public function testOauthApplicationError()
    {
        $encryptorFactory = $this->getEncryptorFactory();
        $encryptorFactory->setComponentId('keboola.docker-demo');
        $config = ['oauth_api' => ['id' => 'test-credentials-45']];

        $oauthClientStub = self::getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $oauthClientStub->expects(self::once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will(self::throwException(new RequestException('Internal Server Error', 500)));
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo');
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('Internal Server Error');
        $auth->getAuthorization($config);
    }

    public function testOauthInjectedSandboxed()
    {
        $encryptorFactory = $this->getEncryptorFactory();
        $encryptorFactory->setComponentId('keboola.docker-demo');
        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com',
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $config = ['oauth_api' => ['credentials' => $encryptorFactory->getEncryptor()->encrypt($credentials)]];

        $oauthClientStub = self::getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo');
        self::assertEquals(
            $config,
            $auth->getAuthorization($config)
        );
    }

    public function testOauthInjectedConfigDecrypt()
    {
        $encryptorFactory = $this->getEncryptorFactory();
        $encryptorFactory->setComponentId('keboola.docker-demo');

        $credentials = [
            'id' => 'test-credentials-45',
            'authorizedFor' => '',
            'creator' => [
                'id' => '3800',
                'description' => 'ondrej.hlavacek@keboola.com',
            ],
            'created' => '2016-02-09 09:47:16',
            '#data' => '{"access_token":"abcd","token_type":"bearer","uid":"efgh"}',
            'oauthVersion' => '2.0',
            'appKey' => '123456',
            '#appSecret' => '654321',
        ];
        $config = ['authorization' => ['oauth_api' => ['credentials' => $credentials]]];

        $temp = new Temp();
        $oauthClientStub = self::getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $oauthClientStub, $encryptorFactory->getEncryptor(), 'keboola.docker-demo');
        $configFile = new ConfigFile($temp->getTmpFolder(), ['fooBar' => 'baz'], $auth, 'run', 'json');
        $configFile->createConfigFile($config, new OutputFilter());
        $data = json_decode(file_get_contents($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'config.json'), true);
        $sampleData = [
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        'id' => 'test-credentials-45',
                        'authorizedFor' => '',
                        'creator' => [
                            'id' => '3800',
                            'description' => 'ondrej.hlavacek@keboola.com',
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
        self::assertEquals($sampleData, $data);
    }
}
