<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Authorization;
use Keboola\DockerBundle\Docker\Runner\ConfigFile;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\OAuthV2Api\Credentials;
use Keboola\OAuthV2Api\Exception\ClientException;
use Keboola\Temp\Temp;

class AuthorizationTest extends BaseRunnerTest
{
    public function testOauthDecrypt(): void
    {
        $encryptor = $this->getEncryptor();
        $oauthClientStub = $this->getMockBuilder(Credentials::class)
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
        $oauthResponse = $encryptor->encryptForProject($credentials, 'keboola.docker-demo', '12345');
        $oauthClientStub->expects(self::once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'whatever')
            ->will(self::returnValue($oauthResponse));
        $config = ['oauth_api' => [
            'id' => 'whatever',
            'version' => 3
        ]];

        $jobScopedEncryptor = new JobScopedEncryptor(
            $encryptor,
            'keboola.docker-demo',
            '12345',
            null
        );

        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $jobScopedEncryptor, 'keboola.docker-demo');
        self::assertEquals(
            $credentials,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthConfigDecrypt(): void
    {
        $encryptor = $this->getEncryptor();
        $oauthClientStub = $this->getMockBuilder(Credentials::class)
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
        $oauthResponse = $encryptor->encryptForProject($credentials, 'keboola.docker-demo', '12345');
        $oauthClientStub->expects(self::once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will(self::returnValue($oauthResponse));
        $config = ['authorization' => ['oauth_api' => [
            'id' => 'test-credentials-45',
            'version' => 3,
        ]]];

        $jobScopedEncryptor = new JobScopedEncryptor(
            $encryptor,
            'keboola.docker-demo',
            '12345',
            null
        );

        $temp = new Temp();
        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $jobScopedEncryptor, 'keboola.docker-demo');
        $configFile = new ConfigFile($temp->getTmpFolder(), $auth, 'run', 'json');
        $configFile->createConfigFile($config, new OutputFilter(10000), [], ['fooBar' => 'baz']);
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
            'storage' => [],
            'parameters' => [],
            'shared_code_row_ids' => [],
        ];
        self::assertEquals($sampleData, $data);
    }

    public function testOauthInjected(): void
    {
        $encryptor = $this->getEncryptor();
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
        $config = ['oauth_api' => [
            'credentials' => $credentials,
            'version' => 3,
        ]];

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $oauthClientStub->expects(self::never())
            ->method('getDetail');

        $jobScopedEncryptor = new JobScopedEncryptor(
            $encryptor,
            'keboola.docker-demo',
            '12345',
            null
        );

        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $jobScopedEncryptor, 'keboola.docker-demo');
        self::assertEquals(
            $credentials,
            $auth->getAuthorization($config)['oauth_api']['credentials']
        );
    }

    public function testOauthUserError(): void
    {
        $encryptor = $this->getEncryptor();
        $config = ['oauth_api' => [
            'id' => 'test-credentials-45',
            'version' => 3,
        ]];

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $oauthClientStub->expects(self::once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will(self::throwException(new ClientException('OAuth API error: No data found for api: keboola.docker-demo', 400)));

        $jobScopedEncryptor = new JobScopedEncryptor(
            $encryptor,
            'keboola.docker-demo',
            '12345',
            null
        );

        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $jobScopedEncryptor, 'keboola.docker-demo');
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('No data found for api');
        $auth->getAuthorization($config);
    }

    public function testOauthApplicationError(): void
    {
        $encryptor = $this->getEncryptor();
        $config = ['oauth_api' => [
            'id' => 'test-credentials-45',
            'version' => 3,
        ]];

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();
        $oauthClientStub->expects(self::once())
            ->method('getDetail')
            ->with('keboola.docker-demo', 'test-credentials-45')
            ->will(self::throwException(new ClientException('Internal Server Error', 500)));

        $jobScopedEncryptor = new JobScopedEncryptor(
            $encryptor,
            'keboola.docker-demo',
            '12345',
            null
        );

        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $jobScopedEncryptor, 'keboola.docker-demo');
        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('Internal Server Error');
        $auth->getAuthorization($config);
    }

    public function testOauthInjectedSandboxed(): void
    {
        $encryptor = $this->getEncryptor();
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
        $config = ['oauth_api' => [
            'credentials' => $encryptor->encryptForProject($credentials, 'keboola.docker-demo', '12345'),
            'version' => 3,
        ]];
        $expectedConfig = $config;
        unset($expectedConfig['oauth_api']['version']);

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();

        $jobScopedEncryptor = new JobScopedEncryptor(
            $encryptor,
            'keboola.docker-demo',
            '12345',
            null
        );

        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $jobScopedEncryptor, 'keboola.docker-demo');
        self::assertEquals(
            $expectedConfig,
            $auth->getAuthorization($config)
        );
    }

    public function testOauthInjectedConfigDecrypt(): void
    {
        $encryptor = $this->getEncryptor();

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
        $config = ['authorization' => ['oauth_api' => [
            'credentials' => $credentials,
            'version' => 3,
        ]]];

        $temp = new Temp();
        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();

        $jobScopedEncryptor = new JobScopedEncryptor(
            $encryptor,
            'keboola.docker-demo',
            '12345',
            null
        );

        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $jobScopedEncryptor, 'keboola.docker-demo');
        $configFile = new ConfigFile($temp->getTmpFolder(), $auth, 'run', 'json');
        $configFile->createConfigFile($config, new OutputFilter(10000), [], ['fooBar' => 'baz']);
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
            'storage' => [],
            'parameters' => [],
            'shared_code_row_ids' => [],
        ];
        self::assertEquals($sampleData, $data);
    }

    public function testOauthV2DeprecationMessage(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('OAuth Broker v2 has been deprecated on September 30, 2019. https://status.keboola.com/end-of-life-old-oauth-broker');

        $encryptor = $this->getEncryptor();
        $config = [
            'oauth_api' => [
                'id' => 'test-deprecated-credentials',
                'version' => 2,
            ],
        ];

        $oauthClientStub = $this->getMockBuilder(Credentials::class)
            ->disableOriginalConstructor()
            ->getMock();

        $jobScopedEncryptor = new JobScopedEncryptor(
            $encryptor,
            'keboola.docker-demo',
            '12345',
            null
        );


        /** @var Credentials $oauthClientStub */
        $auth = new Authorization($oauthClientStub, $jobScopedEncryptor, 'keboola.docker-demo');
        $auth->getAuthorization($config);
    }
}
