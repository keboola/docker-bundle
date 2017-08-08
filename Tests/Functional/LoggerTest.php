<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Syrup\Service\StorageApi\StorageApiService;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Keboola\DockerBundle\Docker\RunCommandOptions;

class LoggerTests extends KernelTestCase
{

    private function getImageConfiguration()
    {
        return [
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/docker-demo-app",
                    "tag" => "latest",
                    "build_options" => [
                        "parent_type" => "quayio",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app.git",
                            "type" => "git"
                        ],
                        "commands" => [],
                        "entry_point" => "php /data/test.php"
                    ],
                ]
            ]
        ];
    }

    private function getGelfImageConfiguration()
    {
        return [
            /* docker-demo app is actually not used here, it is only needed for
            builder (because requires URI, builder is used to override for the entry point. */
            "data" => [
                "definition" => [
                    "type" => "builder",
                    "uri" => "keboola/gelf-test-client",
                    "tag" => "master",
                    "build_options" => [
                        "parent_type" => "quayio",
                        "repository" => [
                            "uri" => "https://github.com/keboola/docker-demo-app.git",
                            "type" => "git"
                        ],
                        "entry_point" => "php /src/UdpClient.php"
                    ],
                ],
                "configuration_format" => "json",
                "logging" => [
                    "type" => "gelf",
                    "gelf_server_type" => "udp"
                ]
            ]
        ];
    }

    private function createScript(Temp $temp, $contents)
    {
        $temp->initRunFolder();
        $dataDir = $temp->getTmpFolder();

        $fs = new Filesystem();
        $fs->dumpFile($dataDir . DIRECTORY_SEPARATOR . 'test.php', $contents);

        return $dataDir;
    }

    private function getContainerDummyLogger(
        $imageConfiguration,
        $dataDir,
        TestHandler $handler,
        TestHandler $containerHandler
    ) {
        /** @var LoggersService $logService */
        $logService = self::$kernel->getContainer()->get('docker_bundle.loggers');
        $logService->setComponentId('dummy-testing');
        $encryptor = new ObjectEncryptor();
        $log = $logService->getLog();
        $containerLog = $logService->getContainerLog();
        $log->pushHandler($handler);
        $containerLog->pushHandler($containerHandler);
        $image = ImageFactory::getImage($encryptor, $log, new Component($imageConfiguration), new Temp(), true);
        $image->prepare([]);
        return new Container(
            'docker-test-logger',
            $image,
            $log,
            $containerLog,
            $dataDir,
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], [])
        );
    }

    private function getContainerStorageLogger($sapiService, $imageConfiguration, $dataDir)
    {
        $serviceContainer = self::$kernel->getContainer();
        /** @var LoggersService $logService */
        /** @var ObjectEncryptor $encryptor */
        $encryptor = $serviceContainer->get('syrup.object_encryptor');
        /** @var LoggersService $logService */
        $logService = $serviceContainer->get('docker_bundle.loggers');
        $logService->setComponentId('dummy-testing');
        /** @var StorageApiService $sapiService */
        $sapiService->setClient(new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]));
        $sapiService->getClient()->setRunId($sapiService->getClient()->generateRunId());
        $image = ImageFactory::getImage($encryptor, $logService->getLog(), new Component($imageConfiguration), new Temp(), true);
        $image->prepare([]);
        $logService->setVerbosity($image->getSourceComponent()->getLoggerVerbosity());
        return new Container(
            'docker-test-logger',
            $image,
            $logService->getLog(),
            $logService->getContainerLog(),
            $dataDir,
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], [])
        );
    }

    public function setUp()
    {
        parent::setUp();
        self::bootKernel();
    }

    public function tearDown()
    {
        parent::tearDown();
        (new Process(
            "sudo docker rmi -f $(sudo docker images -aq --filter \"label=com.keboola.docker.runner.origin=builder\")"
        ))->run();
    }

    public function testLogs()
    {
        $temp = new Temp('docker');
        $handler = new TestHandler();
        $containerHandler = new TestHandler();
        $dataDir = $this->createScript(
            $temp,
            '<?php
echo "first message to stdout\n";
file_put_contents("php://stderr", "first message to stderr\n");
sleep(5);
error_log("second message to stderr\n");
print "second message to stdout\n";'
        );
        $container = $this->getContainerDummyLogger(
            $this->getImageConfiguration(),
            $dataDir,
            $handler,
            $containerHandler
        );
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $this->assertEquals("first message to stdout\nsecond message to stdout\n", $out);
        $this->assertEquals("first message to stderr\nsecond message to stderr\n\n", $err);
        $this->assertTrue($handler->hasDebugRecords());
        $this->assertFalse($handler->hasErrorRecords());
        $records = $handler->getRecords();
        foreach ($records as $record) {
            // todo change this to proper channel, when this is resolved https://github.com/keboola/docker-bundle/issues/64
            $this->assertEquals('docker', $record['app']);
        }

        $records = $containerHandler->getRecords();
        $this->assertEquals(4, count($records));
        $this->assertTrue($containerHandler->hasErrorRecords());
        $this->assertTrue($containerHandler->hasInfoRecords());
        $this->assertTrue($containerHandler->hasInfo("first message to stdout\n"));
        $this->assertTrue($containerHandler->hasInfo("second message to stdout\n"));
        $this->assertTrue($containerHandler->hasError("first message to stderr\n"));
        $this->assertTrue($containerHandler->hasError("second message to stderr\n\n"));
        $records = $containerHandler->getRecords();
        foreach ($records as $record) {
            // todo change this to proper channel, when this is resolved https://github.com/keboola/docker-bundle/issues/64
            $this->assertEquals('docker', $record['app']);
        }
    }

    public function testGelfLogUdp()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getGelfImageConfiguration();
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'udp';
        $imageConfiguration['data']['definition']['build_options']['entry_point'] = 'php /src/UdpClient.php';
        $handler = new TestHandler();
        $containerHandler = new TestHandler();
        $container = $this->getContainerDummyLogger(
            $imageConfiguration,
            $temp->getTmpFolder(),
            $handler,
            $containerHandler
        );
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $records = $handler->getRecords();
        $this->assertGreaterThan(0, count($records));
        $this->assertEquals('', $err);
        $this->assertContains('Client finished', $out);
        $records = $containerHandler->getRecords();
        $this->assertEquals(8, count($records));
        $this->assertTrue($containerHandler->hasDebug("A debug message."));
        $this->assertTrue($containerHandler->hasAlert("An alert message"));
        $this->assertTrue($containerHandler->hasEmergency("Exception example"));
        $this->assertTrue($containerHandler->hasAlert("Structured message"));
        $this->assertTrue($containerHandler->hasWarning("A warning message."));
        $this->assertTrue($containerHandler->hasInfoRecords());
        $this->assertTrue($containerHandler->hasError("Error message."));
    }

    public function testGelfLogTcp()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getGelfImageConfiguration();
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $imageConfiguration['data']['definition']['build_options']['entry_point'] = 'php /src/TcpClient.php';
        $handler = new TestHandler();
        $containerHandler = new TestHandler();
        $container = $this->getContainerDummyLogger(
            $imageConfiguration,
            $temp->getTmpFolder(),
            $handler,
            $containerHandler
        );
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $records = $handler->getRecords();
        $this->assertGreaterThan(0, count($records));
        $this->assertEquals('', $err);
        $this->assertEquals('Client finished', $out);
        $records = $containerHandler->getRecords();
        $this->assertEquals(8, count($records));
        $this->assertTrue($containerHandler->hasDebug("A debug message."));
        $this->assertTrue($containerHandler->hasAlert("An alert message"));
        $this->assertTrue($containerHandler->hasEmergency("Exception example"));
        $this->assertTrue($containerHandler->hasAlert("Structured message"));
        $this->assertTrue($containerHandler->hasWarning("A warning message."));
        $this->assertTrue($containerHandler->hasInfoRecords());
        $this->assertTrue($containerHandler->hasError("Error message."));
    }

    public function testGelfLogHttp()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getGelfImageConfiguration();
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'http';
        $imageConfiguration['data']['definition']['build_options']['entry_point'] = 'php /src/HttpClient.php';
        $handler = new TestHandler();
        $containerHandler = new TestHandler();
        $container = $this->getContainerDummyLogger(
            $imageConfiguration,
            $temp->getTmpFolder(),
            $handler,
            $containerHandler
        );
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $records = $handler->getRecords();
        $this->assertGreaterThan(0, count($records));
        $this->assertEquals('', $err);
        $this->assertEquals('Client finished', $out);
        $records = $containerHandler->getRecords();
        $this->assertEquals(8, count($records));
        $this->assertTrue($containerHandler->hasDebug("A debug message."));
        $this->assertTrue($containerHandler->hasAlert("An alert message"));
        $this->assertTrue($containerHandler->hasEmergency("Exception example"));
        $this->assertTrue($containerHandler->hasAlert("Structured message"));
        $this->assertTrue($containerHandler->hasWarning("A warning message."));
        $this->assertTrue($containerHandler->hasInfoRecords());
        $this->assertTrue($containerHandler->hasError("Error message."));
    }

    public function testVerbosityDefault()
    {
        $imageConfiguration = $this->getGelfImageConfiguration();
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $imageConfiguration['data']['definition']['build_options']['entry_point'] = 'php /src/TcpClient.php';

        $sapiService = self::$kernel->getContainer()->get('syrup.storage_api');
        $temp = new Temp('docker');
        $container = $this->getContainerStorageLogger($sapiService, $imageConfiguration, $temp->getTmpFolder());
        $container->run();

        sleep(5); // give storage a little timeout to realize that events are in
        $events = $sapiService->getClient()->listEvents(
            ['component' => 'dummy-testing', 'runId' => $sapiService->getClient()->getRunId()]
        );
        $this->assertCount(7, $events);
        $error = [];
        $info = [];
        $warn = [];
        foreach ($events as $event) {
            if ($event['type'] == 'error') {
                $error[] = $event['message'];
            }
            if ($event['type'] == 'info') {
                $info[] = $event['message'];
            }
            if ($event['type'] == 'warn') {
                $warn[] = $event['message'];
            }
        }
        $this->assertCount(1, $warn);
        $this->assertEquals('A warning message.', $warn[0]);
        $this->assertCount(2, $info);
        sort($info);
        $this->assertEquals(5827, strlen($info[0]));
        $this->assertEquals('Client finished', $info[1]);
        sort($error);
        $this->assertCount(4, $error);
        $this->assertEquals('Application error', $error[0]);
        $this->assertEquals('Application error', $error[1]);
        $this->assertEquals('Application error', $error[2]);
        $this->assertEquals('Error message.', $error[3]);
    }

    public function testGelfVerbosityVerbose()
    {
        $imageConfiguration = $this->getGelfImageConfiguration();
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $imageConfiguration['data']['definition']['build_options']['entry_point'] = 'php /src/TcpClient.php';
        $imageConfiguration['data']['logging']['verbosity'] = [
            Logger::DEBUG => StorageApiHandler::VERBOSITY_VERBOSE,
            Logger::INFO => StorageApiHandler::VERBOSITY_VERBOSE,
            Logger::NOTICE => StorageApiHandler::VERBOSITY_VERBOSE,
            Logger::WARNING => StorageApiHandler::VERBOSITY_VERBOSE,
            Logger::ERROR => StorageApiHandler::VERBOSITY_VERBOSE,
            Logger::CRITICAL => StorageApiHandler::VERBOSITY_VERBOSE,
            Logger::ALERT => StorageApiHandler::VERBOSITY_VERBOSE,
            Logger::EMERGENCY => StorageApiHandler::VERBOSITY_VERBOSE,
        ];
        $sapiService = self::$kernel->getContainer()->get('syrup.storage_api');
        $temp = new Temp('docker');
        $container = $this->getContainerStorageLogger($sapiService, $imageConfiguration, $temp->getTmpFolder());
        $container->run();

        sleep(5); // give storage a little timeout to realize that events are in
        $events = $sapiService->getClient()->listEvents(
            ['component' => 'dummy-testing', 'runId' => $sapiService->getClient()->getRunId()]
        );
        $this->assertCount(8, $events);
        $error = [];
        $info = [];
        $warn = [];
        $exception = [];
        $structure = [];
        foreach ($events as $event) {
            if ($event['type'] == 'error') {
                $error[] = $event['message'];
            }
            if ($event['type'] == 'info') {
                $info[] = $event['message'];
            }
            if ($event['type'] == 'warn') {
                $warn[] = $event['message'];
            }
            if ($event['message'] == 'Exception example') {
                $exception = $event;
            }
            if ($event['message'] == 'A warning message.') {
                $structure = $event;
            }
        }
        $this->assertCount(1, $warn);
        $this->assertEquals('A warning message.', $warn[0]);
        $this->assertCount(3, $info);
        sort($info);
        $this->assertEquals('A debug message.', $info[0]);
        $this->assertEquals(5827, strlen($info[1]));
        $this->assertEquals('Client finished', $info[2]);
        sort($error);
        $this->assertCount(4, $error);
        $this->assertEquals('An alert message', $error[0]);
        $this->assertEquals('Error message.', $error[1]);
        $this->assertEquals('Exception example', $error[2]);
        $this->assertEquals('Structured message', $error[3]);
        $this->assertNotEmpty($exception);
        $this->assertContains('file', $exception['results']);
        $this->assertEquals('/src/TcpClient.php', $exception['results']['file']);
        $this->assertContains('full_message', $exception['results']);
        $this->assertEquals("Exception: Test exception (0)\n\n#0 {main}\n", $exception['results']['full_message']);
        $this->assertArrayHasKey('several', $structure['results']['_structure']['with']);
        $this->assertEquals('nested', $structure['results']['_structure']['with']['several']);
    }

    public function testGelfVerbosityNone()
    {
        $imageConfiguration = $this->getGelfImageConfiguration();
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $imageConfiguration['data']['definition']['build_options']['entry_point'] = 'php /src/TcpClient.php';
        $imageConfiguration['data']['logging']['verbosity'] = [
            Logger::DEBUG => StorageApiHandler::VERBOSITY_NONE,
            Logger::INFO => StorageApiHandler::VERBOSITY_NONE,
            Logger::NOTICE => StorageApiHandler::VERBOSITY_NONE,
            Logger::WARNING => StorageApiHandler::VERBOSITY_NONE,
            Logger::ERROR => StorageApiHandler::VERBOSITY_NONE,
            Logger::CRITICAL => StorageApiHandler::VERBOSITY_NONE,
            Logger::ALERT => StorageApiHandler::VERBOSITY_NONE,
            Logger::EMERGENCY => StorageApiHandler::VERBOSITY_NONE,
        ];
        $temp = new Temp('docker');
        $sapiService = self::$kernel->getContainer()->get('syrup.storage_api');
        $container = $this->getContainerStorageLogger($sapiService, $imageConfiguration, $temp->getTmpFolder());
        $container->run();

        sleep(5); // give storage a little timeout to realize that events are in
        $events = $sapiService->getClient()->listEvents(
            ['component' => 'dummy-testing', 'runId' => $sapiService->getClient()->getRunId()]
        );
        $this->assertCount(0, $events);
    }

    public function testStdoutVerbosity()
    {
        $imageConfiguration = $this->getImageConfiguration();
        $temp = new Temp('docker');
        $dataDir = $this->createScript(
            $temp,
            '<?php
echo "first message to stdout\n";
file_put_contents("php://stderr", "first message to stderr\n");
sleep(5);
error_log("second message to stderr\n");
print "second message to stdout\n";'
        );
        $sapiService = self::$kernel->getContainer()->get('syrup.storage_api');
        $container = $this->getContainerStorageLogger($sapiService, $imageConfiguration, $dataDir);
        $container->run();

        sleep(5); // give storage a little timeout to realize that events are in
        $events = $sapiService->getClient()->listEvents(
            ['component' => 'dummy-testing', 'runId' => $sapiService->getClient()->getRunId()]
        );
        $this->assertCount(4, $events);
        $error = [];
        $info = [];
        foreach ($events as $event) {
            if ($event['type'] == 'error') {
                $error[] = $event['message'];
            }
            if ($event['type'] == 'info') {
                $info[] = $event['message'];
            }
        }
        $this->assertCount(2, $error);
        sort($error);
        $this->assertEquals("first message to stderr\n", $error[0]);
        $this->assertEquals("second message to stderr\n\n", $error[1]);
        sort($info);
        $this->assertCount(2, $info);
        $this->assertEquals("first message to stdout\n", $info[0]);
        $this->assertEquals("second message to stdout\n", $info[1]);
    }

    public function testRunnerLogs()
    {
        $imageConfiguration = $this->getImageConfiguration();

        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();
        $serviceContainer = $kernel->getContainer();

        /** @var ObjectEncryptor $encryptor */
        $encryptor = $serviceContainer->get('syrup.object_encryptor');
        /** @var LoggersService $logService */
        $logService = $serviceContainer->get('docker_bundle.loggers');
        $logService->setComponentId('dummy-testing');
        /** @var StorageApiService $sapiService */
        $sapiService = $serviceContainer->get('syrup.storage_api');
        $sapiService->setClient(new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]));
        $sapiService->getClient()->setRunId($sapiService->getClient()->generateRunId());

        $image = ImageFactory::getImage($encryptor, $logService->getLog(), new Component($imageConfiguration), new Temp(), true);
        $image->prepare([]);
        $logService->setVerbosity($image->getSourceComponent()->getLoggerVerbosity());
        $logService->getLog()->notice("Test Notice");
        $logService->getLog()->error("Test Error");
        $logService->getLog()->info("Test Info");
        $logService->getLog()->warn("Test Warn");
        $logService->getLog()->debug("Test Debug");

        sleep(5); // give storage a little timeout to realize that events are in
        $events = $sapiService->getClient()->listEvents(
            ['component' => 'dummy-testing', 'runId' => $sapiService->getClient()->getRunId()]
        );
        $this->assertCount(3, $events);
        $error = [];
        $info = [];
        $warn = [];
        foreach ($events as $event) {
            if ($event['type'] == 'error') {
                $error[] = $event['message'];
            }
            if ($event['type'] == 'warn') {
                $warn[] = $event['message'];
            }
            if ($event['type'] == 'info') {
                $info[] = $event['message'];
            }
        }
        $this->assertCount(1, $error);
        $this->assertEquals("Test Error", $error[0]);
        $this->assertCount(1, $info);
        $this->assertEquals("Test Warn", $warn[0]);
        $this->assertCount(1, $warn);
        $this->assertEquals("Test Info", $info[0]);
    }
}
