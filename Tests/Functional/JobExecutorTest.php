<?php

namespace Keboola\DockerBundle\Tests\JobExecutorTest;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Job\Executor;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Service\Runner;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Elasticsearch\JobMapper;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

class JobExecutorTest extends KernelTestCase
{

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Temp
     */
    private $temp;

    private function getJobExecutor(&$encryptor)
    {
        $storageApiClient = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
            'userAgent' => 'docker-bundle',
        ]);

        $tokenData = $storageApiClient->verifyToken();

        $storageServiceStub = $this->getMockBuilder(StorageApiService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $storageServiceStub->expects($this->any())
            ->method("getClient")
            ->will($this->returnValue($this->client));
        $storageServiceStub->expects($this->any())
            ->method("getTokenData")
            ->will($this->returnValue($tokenData));

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLogger = new ContainerLogger("null");
        $containerLogger->pushHandler(new NullHandler());
        $loggersServiceStub = $this->getMockBuilder(LoggersService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $loggersServiceStub->expects($this->any())
            ->method("getLog")
            ->will($this->returnValue($log));
        $loggersServiceStub->expects($this->any())
            ->method("getContainerLog")
            ->will($this->returnValue($containerLogger));

        $jobMapperStub = $this->getMockBuilder(JobMapper::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;

        $encryptor = new ObjectEncryptor();
        $ecWrapper = new ComponentWrapper(hash('sha256', uniqid()));
        $ecWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper = new ComponentProjectWrapper(hash('sha256', uniqid()));
        $ecpWrapper->setComponentId('keboola.r-transformation');
        $ecpWrapper->setProjectId($tokenData["owner"]["id"]);
        $encryptor->pushWrapper($ecWrapper);
        $encryptor->pushWrapper($ecpWrapper);

        /** @var StorageApiService $storageServiceStub */
        /** @var LoggersService $loggersServiceStub */
        /** @var JobMapper $jobMapperStub */
        $runner = new Runner(
            $this->temp,
            $encryptor,
            $storageServiceStub,
            $loggersServiceStub,
            $jobMapperStub,
            "dummy",
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT
        );
        $componentsService = new ComponentsService($storageServiceStub);

        $jobExecutor = new Executor(
            $loggersServiceStub->getLog(),
            $runner,
            $encryptor,
            $componentsService,
            $ecWrapper,
            $ecpWrapper
        );
        $jobExecutor->setStorageApi($this->client);
        return $jobExecutor;
    }

    private function getJobParameters()
    {
        $data = [
            'params' => [
                'component' => 'keboola.r-transformation',
                'mode' => 'run',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [[
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv'
                            ]]
                        ],
                        'output' => [
                            'tables' => [[
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed'
                            ]]
                        ]
                    ],
                    'parameters' => [
                        'script' => [
                            'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                            'tdata <- t(data[, !(names(data) %in% ("name"))])',
                            'colnames(tdata) <- data[["name"]]',
                            'tdata <- data.frame(column = rownames(tdata), tdata)',
                            'write.csv(tdata, file = "/data/out/tables/transpose.csv", row.names = FALSE)'
                        ]
                    ]
                ]
            ]
        ];
        return $data;
    }

    public function setUp()
    {
        $this->client = new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]);
        $this->temp = new Temp('docker');
        $this->temp->initRunFolder();
        if ($this->client->bucketExists("in.c-docker-test")) {
            // Delete tables
            foreach ($this->client->listTables("in.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("in.c-docker-test");
        }
        if ($this->client->bucketExists("out.c-docker-test")) {
            // Delete tables
            foreach ($this->client->listTables("out.c-docker-test") as $table) {
                $this->client->dropTable($table["id"]);
            }

            // Delete bucket
            $this->client->dropBucket("out.c-docker-test");
        }

        // remove uploaded files
        $options = new ListFilesOptions();
        $options->setTags(["docker-bundle-test", "sandbox", "input", "dry-run"]);
        $files = $this->client->listFiles($options);
        foreach ($files as $file) {
            $this->client->deleteFile($file["id"]);
        }

        // Create buckets
        $this->client->createBucket("docker-test", Client::STAGE_IN, "Docker TestSuite");
        $this->client->createBucket("docker-test", Client::STAGE_OUT, "Docker TestSuite");

        self::bootKernel();
    }

    public function testRun()
    {
        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            $csv->writeRow(['price', '100', '1000']);
            $csv->writeRow(['size', 'small', 'big']);
            $csv->writeRow(['age', 'low', 'high']);
            $csv->writeRow(['kindness', 'no', 'yes']);
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $data = $this->getJobParameters();
        $jobExecutor = $this->getJobExecutor($encryptor);
        $job = new Job($encryptor, $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $csvData = $this->client->exportTable('out.c-docker-test.transposed');
        $data = Client::parseCsv($csvData);

        $this->assertEquals(2, count($data));
        $this->assertArrayHasKey('column', $data[0]);
        $this->assertArrayHasKey('price', $data[0]);
        $this->assertArrayHasKey('size', $data[0]);
        $this->assertArrayHasKey('age', $data[0]);
        $this->assertArrayHasKey('kindness', $data[0]);
    }

    public function testSandbox()
    {
         // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
            $fs = new Filesystem();
            unset($csv);
            $fs->remove($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
        }

        $data = $this->getJobParameters();
        $data['params']['mode'] = 'sandbox';
        $jobExecutor = $this->getJobExecutor($encryptor);
        $job = new Job($encryptor, $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        try {
            $this->client->exportTable('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['sandbox']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(500, $files[0]['sizeBytes']);
        $this->assertLessThan(4000, $files[0]['sizeBytes']);
    }

    public function testInput()
    {
        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $data = $this->getJobParameters();
        $data['params']['mode'] = 'input';
        $jobExecutor = $this->getJobExecutor($encryptor);
        $job = new Job($encryptor, $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        try {
            $this->client->exportTable('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['input']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(3800, $files[0]['sizeBytes']);
    }

    public function testDryRun()
    {
        // Create table
        if (!$this->client->tableExists("in.c-docker-test.source")) {
            $csv = new CsvFile($this->temp->getTmpFolder() . DIRECTORY_SEPARATOR . "upload.csv");
            $csv->writeRow(['name', 'oldValue', 'newValue']);
            for ($i = 0; $i < 1000; $i++) {
                $csv->writeRow([$i, '100', '1000']);
            }
            $this->client->createTableAsync("in.c-docker-test", "source", $csv);
        }

        $data = $this->getJobParameters();
        $data['params']['mode'] = 'dry-run';
        $jobExecutor = $this->getJobExecutor($encryptor);
        $job = new Job($encryptor, $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        try {
            $this->client->exportTable('out.c-docker-test.transposed');
            $this->fail("Table should not exist.");
        } catch (Exception $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }
        }

        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['dry-run']);
        $files = $this->client->listFiles($listOptions);
        $this->assertEquals(1, count($files));
        $this->assertEquals(0, strcasecmp('data.zip', $files[0]['name']));
        $this->assertGreaterThan(6100, $files[0]['sizeBytes']);
    }

    public function testIncrementalTags()
    {
        // Create file
        $root = $this->temp->getTmpFolder();
        file_put_contents($root . "/upload", "test");

        $id1 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "toprocess"])
        );
        $id2 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "toprocess"])
        );
        $id3 = $this->client->uploadFile(
            $root . "/upload",
            (new FileUploadOptions())->setTags(["docker-bundle-test", "incremental-test"])
        );

        $data = $this->getJobParameters();
        $data['params']['configData']['storage'] = [
            'input' => [
                'files' => [[
                    'query' => 'tags: toprocess AND NOT tags: downloaded',
                    'processed_tags' => [
                        'downloaded', 'experimental'
                    ],
                ]]
            ]
        ];
        $data['params']['configData']['parameters'] = [
            'script' => [
                "inDirectory <- '/data/in/files/'",
                "outDirectory <- '/data/out/files/'",
                "files <- list.files(inDirectory, pattern = '^[0-9]+_upload$', full.names = FALSE)",
                "for (file in files) {",
                "    fn <- paste0(outDirectory, file, '.csv');",
                "    file.copy(paste0(inDirectory, file), fn);",
                "    app\$writeFileManifest(fn, c('processed', 'docker-bundle-test'))",
                "}"
            ]
        ];
        $jobExecutor = $this->getJobExecutor($encryptor);
        $job = new Job($encryptor, $data);
        $job->setId(123456);
        $jobExecutor->execute($job);

        $listFileOptions = new ListFilesOptions();
        $listFileOptions->setTags(['downloaded']);
        $files = $this->client->listFiles($listFileOptions);
        $ids = [];
        foreach ($files as $file) {
            $ids[] = $file['id'];
        }
        $this->assertContains($id1, $ids);
        $this->assertContains($id2, $ids);
        $this->assertNotContains($id3, $ids);

        $listFileOptions = new ListFilesOptions();
        $listFileOptions->setTags(['processed']);
        $files = $this->client->listFiles($listFileOptions);
        $this->assertEquals(2, count($files));
    }
}
