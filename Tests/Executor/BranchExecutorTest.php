<?php

namespace Keboola\DockerBundle\Tests\Executor;

use Keboola\DockerBundle\Tests\BaseExecutorTest;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Job\Metadata\Job;

class BranchExecutorTest extends BaseExecutorTest
{
    protected function initStorageClient()
    {
        $this->client = new Client(
            [
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN_MASTER,
            ]
        );
    }

    public function testBranchJobBlocked()
    {
        $configuration = [
            'storage' => [],
            'parameters' => ['operation' => 'list'],
        ];
        $components = [
            [
                'id' => 'keboola.python-transformation',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-staging-test',
                        'tag' => '0.0.3',
                    ],
                ],
                'features' => ['dev-branch-job-blocked'],
            ],

        ];
        $this->clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN_MASTER,
            ]])
            ->setMethods(['indexAction'])
            ->getMock();
        $this->clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(['components' => $components, 'services' => [['id' => 'oauth', 'url' => 'https://someurl']]]));

        $jobExecutor = $this->getJobExecutor($configuration, [], [], false, 'my-dev-branch');
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'executor-configuration',
                'branchId' => $this->branchId,
            ],
        ];
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        self::expectException(UserException::class);
        self::expectExceptionMessage('This component cannot be run in a development branch.');
        $jobExecutor->execute($job);
    }

    public function testBranchJobAllowed()
    {
        $configuration = [
            'storage' => [],
            'parameters' => ['operation' => 'list'],
        ];
        $components = [
            [
                'id' => 'keboola.python-transformation',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-staging-test',
                        'tag' => '0.0.3',
                    ],
                ],
                'features' => [],
            ],

        ];
        $this->clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN_MASTER,
            ]])
            ->setMethods(['indexAction'])
            ->getMock();
        $this->clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(['components' => $components, 'services' => [['id' => 'oauth', 'url' => 'https://someurl']]]));

        $jobExecutor = $this->getJobExecutor($configuration, [], [], false, 'my-dev-branch');
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'executor-configuration',
                'branchId' => $this->branchId,
            ],
        ];
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $ret = $jobExecutor->execute($job);
        self::assertArrayHasKey('images', $ret);
    }

    public function testBranchConfigurationUnsafe()
    {
        $configuration = [
            'storage' => [],
            'parameters' => ['operation' => 'list'],
        ];
        $components = [
            [
                'id' => 'keboola.python-transformation',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-staging-test',
                        'tag' => '0.0.3',
                    ],
                ],
                'features' => ['dev-branch-configuration-unsafe'],
            ],

        ];
        $this->clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN_MASTER,
            ]])
            ->setMethods(['indexAction'])
            ->getMock();
        $this->clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(['components' => $components, 'services' => [['id' => 'oauth', 'url' => 'https://someurl']]]));

        $jobExecutor = $this->getJobExecutor($configuration, [], [], false, 'my-dev-branch');
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'executor-configuration',
                'branchId' => $this->branchId,
            ],
        ];
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Is is not safe to run this configuration in a development branch. Please review the configuration.'
        );
        $jobExecutor->execute($job);
    }

    public function testBranchConfigDataUnsafe()
    {
        $configuration = [
            'storage' => [],
            'parameters' => ['operation' => 'list'],
        ];
        $components = [
            [
                'id' => 'keboola.python-transformation',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-staging-test',
                        'tag' => '0.0.3',
                    ],
                ],
                'features' => ['dev-branch-configuration-unsafe'],
            ],

        ];
        $this->clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN_MASTER,
            ]])
            ->setMethods(['indexAction'])
            ->getMock();
        $this->clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(['components' => $components, 'services' => [['id' => 'oauth', 'url' => 'https://someurl']]]));

        $jobExecutor = $this->getJobExecutor($configuration, [], [], false, 'my-dev-branch');
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'configData' => $configuration,
                'branchId' => $this->branchId,
            ],
        ];
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        self::expectException(UserException::class);
        self::expectExceptionMessage(
            'Is is not safe to run this configuration in a development branch. Please review the configuration.'
        );
        $jobExecutor->execute($job);
    }

    public function testBranchConfigurationSafe()
    {
        $configuration = [
            'storage' => [],
            'parameters' => ['operation' => 'list'],
            'runtime' => ['safe' => true],
        ];
        $components = [
            [
                'id' => 'keboola.python-transformation',
                'data' => [
                    'definition' => [
                        'type' => 'aws-ecr',
                        'uri' => '061240556736.dkr.ecr.us-east-1.amazonaws.com/keboola.runner-staging-test',
                        'tag' => '0.0.3',
                    ],
                ],
                'features' => ['dev-branch-configuration-unsafe'],
            ],

        ];
        $this->clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([[
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN_MASTER,
            ]])
            ->setMethods(['indexAction'])
            ->getMock();
        $this->clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(['components' => $components, 'services' => [['id' => 'oauth', 'url' => 'https://someurl']]]));

        $jobExecutor = $this->getJobExecutor($configuration, [], [], false, 'my-dev-branch');
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'executor-configuration',
                'branchId' => $this->branchId,
            ],
        ];
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $ret = $jobExecutor->execute($job);
        self::assertArrayHasKey('images', $ret);
    }
}
