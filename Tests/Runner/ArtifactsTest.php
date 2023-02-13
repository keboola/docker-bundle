<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\Artifacts\Result;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Docker\Runner\UsageFile\NullUsageFile;
use Keboola\DockerBundle\Tests\BaseRunnerTest;
use Keboola\StorageApi\Client as StorageApiClient;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Process\Process;

class ArtifactsTest extends BaseRunnerTest
{
    private const PYTHON_TRANSFORMATION_BASIC_CONFIG = [
        'storage' => [],
        'parameters' => [
            'script' => [
                'import os',
                'path = "/data/artifacts/out/current"',
                'if not os.path.exists(path):os.makedirs(path)',
                'pathShared = "/data/artifacts/out/shared"',
                'if not os.path.exists(path):os.makedirs(pathShared)',
                'with open("/data/artifacts/out/current/myartifact1", "w") as file:',
                '   file.write("value1")',
                'with open("/data/artifacts/out/current/myartifact2", "w") as file:',
                '   file.write("value2")',
                'with open("/data/artifacts/out/shared/myartifact1", "w") as file:',
                '   file.write("value1")',
                'with open("/data/artifacts/out/shared/myartifact2", "w") as file:',
                '   file.write("value2")',
            ],
        ],
    ];

    private function getStorageClientMockUpload(): MockObject
    {
        $storageApiMock = $this->getMockBuilder(StorageApiClient::class)
            ->setConstructorArgs([[
                'url' => getenv('STORAGE_API_URL'),
                'token' => getenv('STORAGE_API_TOKEN'),
            ]])
            ->onlyMethods(['verifyToken', 'listFiles'])
            ->getMock()
        ;
        $storageApiMock->method('verifyToken')->willReturn([
            'owner' => [
                'id' => '1234',
                'fileStorageProvider' => 'local',
                'features' => ['artifacts'],
            ],
        ]);
        $storageApiMock->method('listFiles')->willReturn([]);
        $this->setClientMock($storageApiMock);

        return $storageApiMock;
    }

    private function getStorageClientMockDownload(): MockObject
    {
        $storageApiMock = $this->getMockBuilder(StorageApiClient::class)
            ->setConstructorArgs([[
                'url' => getenv('STORAGE_API_URL'),
                'token' => getenv('STORAGE_API_TOKEN'),
            ]])
            ->onlyMethods(['verifyToken'])
            ->getMock()
        ;
        $storageApiMock->method('verifyToken')->willReturn([
            'owner' => [
                'id' => '1234',
                'fileStorageProvider' => 'local',
                'features' => ['artifacts'],
            ],
        ]);
        $this->setClientMock($storageApiMock);

        return $storageApiMock;
    }


    private function runRunner(array $configuration, string $jobId, ?string $orchestrationId = null): array
    {
        $componentData = [
            'id' => 'keboola.python-transformation',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                ],
            ],
        ];

        $runner = $this->getRunner();
        $outputs = [];
        $runner->run(
            $this->prepareJobDefinitions(
                $componentData,
                $configuration['id'],
                $configuration['configuration'],
                []
            ),
            'run',
            'run',
            $jobId,
            new NullUsageFile(),
            [],
            $outputs,
            null,
            $orchestrationId
        );

        return $outputs;
    }

    private function listStorageFiles(string $query, int $limit = 1): array
    {
        return $this->client->listFiles(
            (new ListFilesOptions())
                ->setQuery($query)
                ->setLimit($limit)
        );
    }

    private function createConfiguration(MockObject $storageApiMock, array $configuration): array
    {
        /** @var StorageApiClient&MockObject $storageApiMock */
        $components = new Components($storageApiMock);

        return $components->addConfiguration(
            (new Configuration())
                ->setComponentId('keboola.python-transformation')
                ->setConfiguration($configuration)
                ->setName('artifacts tests')
        );
    }

    public function testArtifactsUpload(): void
    {
        /** @var StorageApiClient&MockObject $storageApiMock */
        $storageApiMock = $this->getStorageClientMockUpload();
        $configuration = $this->createConfiguration($storageApiMock, self::PYTHON_TRANSFORMATION_BASIC_CONFIG);
        $configId = $configuration['id'];
        $jobId = (string) random_int(0, 999999);
        $orchestrationId = (string) random_int(0, 999999);

        $outputs = $this->runRunner($configuration, $jobId, $orchestrationId);
        sleep(2);

        // current
        $files = $this->listStorageFiles(sprintf(
            'tags:(artifact AND branchId-default AND componentId-keboola.python-transformation '
            . 'AND configId-%s AND jobId-%s NOT shared)',
            $configId,
            $jobId
        ));

        $currentFile = $files[0];
        self::assertEquals('artifacts.tar.gz', $currentFile['name']);
        self::assertContains('branchId-default', $currentFile['tags']);
        self::assertContains('componentId-keboola.python-transformation', $currentFile['tags']);
        self::assertContains('configId-' . $configId, $currentFile['tags']);
        self::assertContains('jobId-' . $jobId, $currentFile['tags']);

        // shared
        $files = $this->listStorageFiles(sprintf(
            'tags:(artifact AND shared AND branchId-default '
            . 'AND componentId-keboola.python-transformation AND orchestrationId-%s)',
            $orchestrationId
        ));

        $sharedFile = $files[0];
        self::assertEquals('artifacts.tar.gz', $sharedFile['name']);
        self::assertContains('branchId-default', $sharedFile['tags']);
        self::assertContains('componentId-keboola.python-transformation', $sharedFile['tags']);
        self::assertContains('configId-' . $configId, $sharedFile['tags']);
        self::assertContains('jobId-' . $jobId, $sharedFile['tags']);
        self::assertContains('orchestrationId-' . $orchestrationId, $sharedFile['tags']);
        self::assertContains('shared', $sharedFile['tags']);

        /** @var Output $output */
        $output = $outputs[0];
        self::assertSame(
            [
                new Result($currentFile['id']),
                new Result($sharedFile['id'], true),
            ],
            $output->getArtifactsUploaded()
        );
    }

    public function testArtifactsUploadNoZip(): void
    {
        $storageApiMock = $this->getStorageClientMockUpload();
        $config = array_merge(self::PYTHON_TRANSFORMATION_BASIC_CONFIG, [
            'artifacts' => [
                'options' => [
                    'zip' => false,
                ],
            ],
        ]);
        $configuration = $this->createConfiguration($storageApiMock, $config);
        $configId = $configuration['id'];
        $jobId = (string) random_int(0, 999999);
        $orchestrationId = (string) random_int(0, 999999);

        $outputs = $this->runRunner($configuration, $jobId, $orchestrationId);
        sleep(3);

        // current
        $currentFiles = $this->listStorageFiles(sprintf(
            'tags:(artifact AND branchId-default AND componentId-keboola.python-transformation '
            . 'AND configId-%s AND jobId-%s NOT shared)',
            $configId,
            $jobId
        ), 10);

        usort($currentFiles, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $currentFile = $currentFiles[0];
        self::assertEquals('myartifact1', $currentFile['name']);
        self::assertContains('branchId-default', $currentFile['tags']);
        self::assertContains('componentId-keboola.python-transformation', $currentFile['tags']);
        self::assertContains('configId-' . $configId, $currentFile['tags']);
        self::assertContains('jobId-' . $jobId, $currentFile['tags']);

        // shared
        $sharedFiles = $this->listStorageFiles(sprintf(
            'tags:(artifact AND shared AND branchId-default '
            . 'AND componentId-keboola.python-transformation AND orchestrationId-%s)',
            $orchestrationId
        ), 10);

        usort($sharedFiles, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $sharedFile = $sharedFiles[0];
        self::assertEquals('myartifact1', $sharedFile['name']);
        self::assertContains('branchId-default', $sharedFile['tags']);
        self::assertContains('componentId-keboola.python-transformation', $sharedFile['tags']);
        self::assertContains('configId-' . $configId, $sharedFile['tags']);
        self::assertContains('jobId-' . $jobId, $sharedFile['tags']);
        self::assertContains('orchestrationId-' . $orchestrationId, $sharedFile['tags']);
        self::assertContains('shared', $sharedFile['tags']);

        /** @var Output $output */
        $output = $outputs[0];
        $uploadedResult = $output->getArtifactsUploaded();

        self::assertContainsEquals(new Result($currentFiles[0]['id']), $uploadedResult);
        self::assertContainsEquals(new Result($currentFiles[1]['id']), $uploadedResult);
        self::assertContainsEquals(new Result($currentFiles[0]['id'], true), $uploadedResult);
        self::assertContainsEquals(new Result($currentFiles[1]['id'], true), $uploadedResult);
    }

    public function testArtifactsUploadEmpty(): void
    {
        $storageApiMock = $this->getStorageClientMockUpload();
        $config = [
            'storage' => [],
            'parameters' => [
                'script' => [
                    'import os',
                    '# do nothing',
                ],
            ],
        ];
        $configuration = $this->createConfiguration($storageApiMock, $config);
        $configId = $configuration['id'];
        $jobId = (string) random_int(0, 999999);

        $outputs = $this->runRunner($configuration, $jobId);
        sleep(2);

        $files = $this->client->listFiles(
            (new ListFilesOptions())
                ->setQuery(sprintf(
                    'tags:(artifact AND branchId-default AND componentId-keboola.python-transformation '
                    . 'AND configId-%s AND jobId-%s)',
                    $configId,
                    $jobId
                ))
                ->setLimit(1)
        );
        self::assertEmpty($files);

        /** @var Output $output */
        $output = $outputs[0];
        self::assertEmpty($output->getArtifactsUploaded());
    }

    public function testArtifactsDownload(): void
    {
        $storageApiMock = $this->getStorageClientMockDownload();

        $previousJobId = rand(0, 999999);
        $config = [
            'storage' => [],
            'parameters' => [
                'script' => [
                    'import os',
                    sprintf('with open("/data/artifacts/in/runs/jobId-%s/artifact1", "r") as f:', $previousJobId),
                    '   print(f.read())',
                ],
            ],
            'artifacts' => [
                'runs' => [
                    'enabled' => true,
                    'filter' => [
                        'limit' => 1,
                    ],
                ],
            ],
        ];
        $configuration = $this->createConfiguration($storageApiMock, $config);
        $configId = $configuration['id'];

        if (!is_dir('/tmp/artifact/')) {
            mkdir('/tmp/artifact/');
        }
        file_put_contents('/tmp/artifact/artifact1', 'value1');

        $process = new Process([
            'tar',
            '-C',
            '/tmp/artifact',
            '-czvf',
            '/tmp/artifacts.tar.gz',
            '.',
        ]);
        $process->mustRun();

        $uploadedFileId = $this->client->uploadFile(
            '/tmp/artifacts.tar.gz',
            (new FileUploadOptions())
                ->setTags([
                    'artifact',
                    'branchId-default',
                    'componentId-keboola.python-transformation',
                    'configId-' . $configId,
                    'jobId-' . $previousJobId,
                ])
        );

        sleep(2);

        $outputs = $this->runRunner($configuration, '1234567');

        /** @var Output $output */
        $output = $outputs[0];
        self::assertStringContainsString('value1', $output->getProcessOutput());
        self::assertSame([new Result($uploadedFileId)], $output->getArtifactsDownloaded());
    }

    public function testArtifactsDownloadEmpty(): void
    {
        $storageApiMock = $this->getStorageClientMockDownload();
        $config = [
            'storage' => [],
            'parameters' => [
                'script' => [
                    'import os',
                    '# do nothing',
                ],
            ],
            'artifacts' => [
                'runs' => [
                    'enabled' => false,
                ],
            ],
        ];
        $configuration = $this->createConfiguration($storageApiMock, $config);

        if (!is_dir('/tmp/artifact/')) {
            mkdir('/tmp/artifact/');
        }
        sleep(2);

        $outputs = $this->runRunner($configuration, '1234567');

        /** @var Output $output */
        $output = $outputs[0];
        self::assertSame([], $output->getArtifactsDownloaded());
    }

    public function testArtifactsDownloadNoZip(): void
    {
        $storageApiMock = $this->getStorageClientMockDownload();
        $previousJobId = (string) rand(0, 999999);
        $config = [
            'storage' => [],
            'parameters' => [
                'script' => [
                    'import os',
                    sprintf('with open("/data/artifacts/in/runs/jobId-%s/artifact1", "r") as f:', $previousJobId),
                    '   print(f.read())',
                ],
            ],
            'artifacts' => [
                'options' => [
                    'zip' => false,
                ],
                'runs' => [
                    'enabled' => true,
                    'filter' => [
                        'limit' => 5,
                    ],
                ],
            ],
        ];
        $configuration = $this->createConfiguration($storageApiMock, $config);
        $configId = $configuration['id'];

        $filesToUpload = [];
        $filesToUpload[] = $this->createTmpArtifactFile('artifact1', 'value1');
        $filesToUpload[] = $this->createTmpArtifactFile('artifact2', 'value2');
        $filesToUpload[] = $this->createTmpArtifactFile('artifact3', 'value3', 'folder');
        $uploadedFiles = $this->uploadToStorage($filesToUpload, $configId, $previousJobId);
        sleep(2);

        $outputs = $this->runRunner($configuration, '1234567');

        /** @var Output $output */
        $output = $outputs[0];
        self::assertStringContainsString('value1', $output->getProcessOutput());
        self::assertSame([
            new Result($uploadedFiles[2]),
            new Result($uploadedFiles[1]),
            new Result($uploadedFiles[0]),
        ], $output->getArtifactsDownloaded());
    }

    private function createTmpArtifactFile(string $filename, string $content, ?string $subFolder = null): string
    {
        if (!is_dir('/tmp/artifact/')) {
            mkdir('/tmp/artifact/');
        }
        if ($subFolder) {
            if (!is_dir('/tmp/artifact/'. $subFolder)) {
                mkdir('/tmp/artifact/' . $subFolder);
            }
            $filename = $subFolder . '/' . $filename;
        }
        $path = '/tmp/artifact/' . $filename;
        file_put_contents($path, $content);

        return $path;
    }

    private function uploadToStorage(array $files, string $configId, string $jobId): array
    {
        $uploadedFileIds = [];
        foreach ($files as $filePath) {
            $uploadedFileIds[] = $this->client->uploadFile(
                $filePath,
                (new FileUploadOptions())
                    ->setTags([
                        'artifact',
                        'branchId-default',
                        'componentId-keboola.python-transformation',
                        'configId-' . $configId,
                        'jobId-' . $jobId,
                    ])
            );
        }

        return $uploadedFileIds;
    }
}
