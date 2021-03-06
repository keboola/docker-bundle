<?php

namespace Keboola\DockerBundle\Tests\Executor;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Tests\BaseExecutorTest;
use Keboola\OAuthV2Api\Credentials;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Job\Metadata\Job;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;

class JobExecutorInlineConfigTest extends BaseExecutorTest
{
    private function getJobParameters()
    {
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-executor-test.source',
                                    'destination' => 'input.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'result.csv',
                                    'destination' => 'out.c-executor-test.output',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'script' => [
                            'from shutil import copyfile',
                            'copyfile("/data/in/tables/input.csv", "/data/out/tables/result.csv")',
                        ],
                    ],
                ],
            ],
        ];

        return $data;
    }

    public function testRun()
    {
        $this->createBuckets();
        $componentData = [
            'id' => 'keboola.python-transformation',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];
        $clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([['token' => STORAGE_API_TOKEN, 'url' => STORAGE_API_URL]])
            ->setMethods(['indexAction', 'verifyToken'])
            ->getMock();
        $clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(['services' => [['id' => 'oauth', 'url' => 'https://someurl']], 'components' => [$componentData]]));
        $clientMock->expects(self::any())
            ->method('verifyToken')
            ->willReturn(['owner' => ['id' => '321', 'name' => 'Name'], 'id' => '123', 'description' => 'Description']);
        $this->setClientMock($clientMock);
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        $csv->writeRow(['price', '100', '1000']);
        $csv->writeRow(['size', 'small', 'big']);
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);

        $handler = new TestHandler();
        $data = $this->getJobParameters();
        $jobExecutor = $this->getJobExecutor([], []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $ret = $jobExecutor->execute($job);
        self::assertArrayHasKey('message', $ret);
        self::assertArrayHasKey('images', $ret);
        self::assertArrayHasKey('configVersion', $ret);
        self::assertEquals(null, $ret['configVersion']);

        $csvData = $this->getClient()->getTableDataPreview('out.c-executor-test.output');
        $data = Client::parseCsv($csvData);
        usort($data, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        self::assertEquals(
            [
                [
                    'name' => 'price',
                    'oldValue' => '100',
                    'newValue' => '1000',
                ],
                [
                    'name' =>  'size',
                    'oldValue' => 'small',
                    'newValue' => 'big',
                ],
            ],
            $data
        );
        self::assertFalse($handler->hasWarningThatContains('Overriding component tag'));
    }

    public function testRunInvalidRowId()
    {
        $data = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'configData' => [
                    'storage' => [],
                    'parameters' => [],
                ],
                'row' => [1, 2, 3]
            ],
        ];
        $jobExecutor = $this->getJobExecutor([], []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        self::expectException(UserException::class);
        self::expectExceptionMessage('Unsupported row value');
        $jobExecutor->execute($job);
    }

    public function testRunOAuthSecured()
    {
        $data = $this->getJobParameters();
        $data['params']['configData']['authorization']['oauth_api']['id'] = '12345';
        $data['params']['configData']['authorization']['oauth_api']['version'] = 3;
        $data['params']['configData']['storage'] = [];
        $data['params']['configData']['parameters']['script'] = [
            'from pathlib import Path',
            'import sys',
            'contents = Path("/data/config.json").read_text()',
            'print(contents, file=sys.stderr)',
        ];

        $credentials = [
            '#first' => 'superDummySecret',
            'third' => 'fourth',
            'fifth' => [
                '#sixth' => 'anotherTopSecret'
            ]
        ];
        $credentialsEncrypted = $this->getEncryptorFactory()->getEncryptor()->encrypt($credentials, ComponentProjectWrapper::class);

        $oauthStub = self::getMockBuilder(Credentials::class)
            ->setMethods(['getDetail'])
            ->disableOriginalConstructor()
            ->getMock();
        $oauthStub->method('getDetail')->willReturn($credentialsEncrypted);
        $runner = $this->getRunner();
        // inject mock OAuth client inside Runner
        $prop = new \ReflectionProperty($runner, 'oauthClient3');
        $prop->setAccessible(true);
        $prop->setValue($runner, $oauthStub);

        $jobExecutor = $this->getJobExecutor([], []);
        $prop = new \ReflectionProperty($jobExecutor, 'runner');
        $prop->setAccessible(true);
        $prop->setValue($jobExecutor, $runner);

        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $output = '';
        foreach ($this->getContainerHandler()->getRecords() as $record) {
            if ($record['level'] == Logger::ERROR) {
                $output .= $record['message'];
            }
        }
        $expectedConfig = [
            'parameters' => $data['params']['configData']['parameters'],
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        '#first' => '[hidden]',
                        'third' => 'fourth',
                        'fifth' => [
                            '#sixth' => '[hidden]',
                        ],
                    ],
                    'version' => 2,
                ],
            ],
            'image_parameters' => [],
            'action' => 'run',
            'storage' => [],
            'shared_code_row_ids' => [],
        ];
        $expectedConfigRaw = $expectedConfig;
        $expectedConfigRaw['authorization']['oauth_api']['credentials']['#first'] = 'topSecret';
        $expectedConfigRaw['authorization']['oauth_api']['credentials']['fifth']['#sixth'] = 'topSecret';
        self::assertEquals($expectedConfig, json_decode($output, true));
    }

    public function testRunOAuthObfuscated()
    {
        $data = $this->getJobParameters();
        $data['params']['component'] = 'keboola.python-transformation';
        $data['params']['configData']['authorization']['oauth_api']['id'] = '12345';
        $data['params']['configData']['authorization']['oauth_api']['version'] = 3;
        $data['params']['configData']['storage'] = [];
        $data['params']['configData']['parameters']['script'] = [
            'from pathlib import Path',
            'import sys',
            'import base64',
            // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str)
            'contents = Path("/data/config.json").read_text()[::-1]',
            'print(base64.standard_b64encode(contents.encode("utf-8")).decode("utf-8"), file=sys.stderr)',
        ];
        $credentials = [
            '#first' => 'superDummySecret',
            'third' => 'fourth',
            'fifth' => [
                '#sixth' => 'anotherTopSecret'
            ]
        ];
        $credentialsEncrypted = $this->getEncryptorFactory()->getEncryptor()->encrypt($credentials, ComponentProjectWrapper::class);

        $oauthStub = self::getMockBuilder(Credentials::class)
            ->setMethods(['getDetail'])
            ->disableOriginalConstructor()
            ->getMock();
        $oauthStub->method('getDetail')->willReturn($credentialsEncrypted);
        // inject mock OAuth client inside Runner
        $runner = $this->getRunner();
        $prop = new \ReflectionProperty($runner, 'oauthClient3');
        $prop->setAccessible(true);
        $prop->setValue($runner, $oauthStub);

        $jobExecutor = $this->getJobExecutor([], []);
        $prop = new \ReflectionProperty($jobExecutor, 'runner');
        $prop->setAccessible(true);
        $prop->setValue($jobExecutor, $runner);

        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $output = '';
        foreach ($this->getContainerHandler()->getRecords() as $record) {
            if ($record['level'] == Logger::ERROR) {
                $output .= $record['message'];
            }
        }
        $expectedConfig = [
            'parameters' => $data['params']['configData']['parameters'],
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        '#first' => 'superDummySecret',
                        'third' => 'fourth',
                        'fifth' => [
                            '#sixth' => 'anotherTopSecret',
                        ],
                    ],
                    'version' => 2,
                ],
            ],
            'image_parameters' => [],
            'action' => 'run',
            'storage' => [],
            'shared_code_row_ids' => [],
        ];
        self::assertEquals($expectedConfig, json_decode(strrev(base64_decode($output)), true));
    }


    public function testRunReadonly()
    {
        $data = $this->getJobParameters();
        $this->client = new Client(
            [
                'url' => STORAGE_API_URL,
                'token' => STORAGE_API_TOKEN_READ_ONLY,
            ]
        );

        $jobExecutor = $this->getJobExecutor([], [], [], true);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        self::expectExceptionMessage('As a readOnly user you cannot run a job.');
        self::expectException(UserException::class);
        $jobExecutor->execute($job);
    }

    /**
     * @dataProvider tagOverrideTestDataProvider
     */
    public function testTagOverride($requestConfigTag, $requestParamsTag, $expectedVersion)
    {
        $requestData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'configData' => [
                    'parameters' => [
                        'script' => [
                            'print("Hello world!")',
                        ],
                    ],
                ],
            ],
        ];

        if ($requestConfigTag !== null) {
            $requestData['params']['configData']['runtime']['image_tag'] = $requestConfigTag;
        }

        if ($requestParamsTag !== null) {
            $requestData['params']['tag'] = $requestParamsTag;
        }

        $jobExecutor = $this->getJobExecutor([], []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $requestData);
        $job->setId(123456);
        $jobExecutor->execute($job);

        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains(
            sprintf('Using component tag: "%s"', $expectedVersion)
        ));
    }

    public function tagOverrideTestDataProvider()
    {
        yield 'no override' => [
            'requestConfigTag' => null,
            'requestParamsTag' => null,
            'expectedVersion' => '1.6.0',
        ];

        yield 'request config' => [
            'requestConfigTag' => '1.2.6',
            'requestParamsTag' => null,
            'expectedVersion' => '1.2.6',
        ];

        yield 'request params' => [
            'requestConfigTag' => null,
            'requestParamsTag' => '1.2.7',
            'expectedVersion' => '1.2.7',
        ];

        yield 'all ways' => [
            'requestConfigTag' => '1.2.6',
            'requestParamsTag' => '1.2.7',
            'expectedVersion' => '1.2.7',
        ];
    }

    public function testStoredConfigTagIsOverriddenByRequestEvenIfNoTag()
    {
        $requestData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'configData' => [
                    'parameters' => [
                        'script' => [
                            'print("Hello world!")',
                        ],
                    ],
                ],
            ],
        ];
        $stored = [
            'runtime' => [
                'image_tag' => 'never used',
            ],
        ];

        $jobExecutor = $this->getJobExecutor($stored, []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $requestData);
        $job->setId(123456);
        $jobExecutor->execute($job);

        self::assertTrue($this->getRunnerHandler()->hasInfoThatContains(
            sprintf('Using component tag: "%s"', '1.6.0')
        ));
    }

    public function testIncrementalTags()
    {
        $this->clearFiles();
        // Create file
        $root = $this->getTemp()->getTmpFolder();
        file_put_contents($root . '/upload', 'test');

        $id1 = $this->getClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['executor-test', 'toprocess'])
        );
        $id2 = $this->getClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['executor-test', 'toprocess'])
        );
        $id3 = $this->getClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['executor-test', 'incremental-test'])
        );
        sleep(1);

        $data = $this->getJobParameters();
        $data['params']['configData']['storage'] = [
            'input' => [
                'files' => [
                    [
                        'query' => 'tags: toprocess AND NOT tags: downloaded',
                        'processed_tags' => [
                            'downloaded',
                            'experimental',
                        ],
                    ],
                ],
            ],
        ];
        $data['params']['configData']['parameters'] = [
            'script' => [
                'from shutil import copyfile',
                'import ntpath',
                'import json',
                'for filename in os.listdir("/data/in/files/"):',
                '   if not filename.endswith(".manifest"):',
                '       print("ntp" + filename)',
                '       copyfile("/data/in/files/" + filename, "/data/out/files/" + filename)',
                '       with open("/data/out/files/" + filename + ".manifest", "w") as outfile:',
                '           data = {"tags": ["executor-test", "processed"]}',
                '           json.dump(data, outfile)',
            ],
        ];
        $jobExecutor = $this->getJobExecutor([], []);
        $job = new Job($this->getEncryptorFactory()->getEncryptor(), $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        sleep(1);
        $listFileOptions = new ListFilesOptions();
        $listFileOptions->setTags(['downloaded']);
        $files = $this->getClient()->listFiles($listFileOptions);
        $ids = [];
        foreach ($files as $file) {
            $ids[] = $file['id'];
        }
        self::assertContains($id1, $ids);
        self::assertContains($id2, $ids);
        self::assertNotContains($id3, $ids);

        $listFileOptions = new ListFilesOptions();
        $listFileOptions->setTags(['processed']);
        $files = $this->getClient()->listFiles($listFileOptions);
        self::assertEquals(2, count($files));
    }
}
