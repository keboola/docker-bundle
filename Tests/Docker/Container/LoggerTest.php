<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Tests\BaseContainerTest;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\DockerBundle\Service\StorageApiService;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Keboola\DockerBundle\Docker\RunCommandOptions;

class LoggerTest extends BaseContainerTest
{

    private function getImageConfiguration()
    {
        return [
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    'tag' => 'latest',
                ],
                "image_parameters" => [
                    "#secure" => "secure",
                    "not-secure" => [
                        "this" => "public",
                        "#andthis" => "isAlsoSecure",
                        "#a" => "nested",
                        "#b" => "Structured",
                    ]
                ]
            ],
        ];
    }

    private function getGelfImageConfiguration()
    {
        return [
            "data" => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
                    'tag' => 'latest',
                ],
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
        $fs->dumpFile($dataDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'test.php', $contents);

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
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();
        $log = $logService->getLog();
        $containerLog = $logService->getContainerLog();
        $log->pushHandler($handler);
        $containerLog->pushHandler($containerHandler);
        $image = ImageFactory::getImage($encryptor, $log, new Component($imageConfiguration), new Temp(), true);
        $image->prepare([]);
        $outputFilter = new OutputFilter();
        $outputFilter->collectValues([$this->getImageConfiguration()]);
        return new Container(
            'docker-test-logger',
            $image,
            $log,
            $containerLog,
            $dataDir . '/data',
            $dataDir . '/tmp',
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], []),
            $outputFilter,
            new Limits($log, ['cpu_count' => 2], [], [], [])
        );
    }

    private function getContainerStorageLogger($sapiService, $imageConfiguration, $dataDir)
    {
        $serviceContainer = self::$kernel->getContainer();
        /** @var LoggersService $logService */
        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();
        /** @var LoggersService $logService */
        $logService = $serviceContainer->get('docker_bundle.loggers');
        $logService->setComponentId('dummy-testing');
        /** @var StorageApiService $sapiService */
        $sapiService->setClient(new Client([
            'url' => STORAGE_API_URL,
            'token' => STORAGE_API_TOKEN,
        ]));
        $sapiService->getClient()->setRunId($sapiService->getClient()->generateRunId());
        $image = ImageFactory::getImage(
            $encryptor,
            $logService->getLog(),
            new Component($imageConfiguration),
            new Temp(),
            true
        );
        $image->prepare([]);
        $logService->setVerbosity($image->getSourceComponent()->getLoggerVerbosity());
        $outputFilter = new OutputFilter();
        $outputFilter->collectValues([$this->getImageConfiguration()]);
        return new Container(
            'docker-test-logger',
            $image,
            $logService->getLog(),
            $logService->getContainerLog(),
            $dataDir . '/data',
            $dataDir . '/tmp',
            RUNNER_COMMAND_TO_GET_HOST_IP,
            RUNNER_MIN_LOG_PORT,
            RUNNER_MAX_LOG_PORT,
            new RunCommandOptions([], []),
            $outputFilter,
            new Limits($logService->getLog(), ['cpu_count' => 2], [], [], [])
        );
    }

    public function testLogs()
    {
        $script = [
            'import sys',
            'print("What is public is not secure", file=sys.stdout)',
            'print("Message to stderr isAlsoSecure", file=sys.stderr)',
        ];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $process = $container->run();
        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $this->assertContains("What is public is not [hidden]", $out);
        $this->assertContains("Message to stderr [hidden]", $err);
        $this->assertTrue($this->getLogHandler()->hasNoticeRecords());
        $this->assertFalse($this->getLogHandler()->hasErrorRecords());
        $this->assertTrue($this->getContainerLogHandler()->hasInfoThatContains("What is public is not [hidden]"));
        $this->assertTrue($this->getContainerLogHandler()->hasErrorThatContains("Message to stderr [hidden]"));
        $records = $this->getContainerLogHandler()->getRecords();
        $this->assertGreaterThan(2, count($records));
    }

    public function testGelfLogUdp()
    {
        $script = [
            'import subprocess',
            'import sys',
            'subprocess.call([sys.executable, "-m", "pip", "install", "--disable-pip-version-check", "pygelf"])',
            'import logging',
            'from pygelf import GelfUdpHandler',
            'import os',
            'logging.basicConfig(level=logging.DEBUG)',
            'logger = logging.getLogger()',
            'logger.removeHandler(logging.getLogger().handlers[0])',
            'logger.addHandler(GelfUdpHandler(host=os.getenv("KBC_LOGGER_ADDR"), port=int(os.getenv("KBC_LOGGER_PORT"))))',
            'logging.debug("A debug message.")',
            'logging.info("An info message.")',
            'logging.warning("A warning message with secure secret.")',
            'logging.error("An error message.")',
            'logging.critical("A critical example.")',
            'print("Client finished")',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'udp';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $this->assertEquals('', $err);
        $this->assertContains('Client finished', $out);

        $records = $this->getLogHandler()->getRecords();
        $this->assertGreaterThan(0, count($records));
        $records = $this->getContainerLogHandler()->getRecords();
        $this->assertEquals(6, count($records));
        $this->assertTrue($this->getContainerLogHandler()->hasDebug('A debug message.'));
        $this->assertTrue($this->getContainerLogHandler()->hasInfo('An info message.'));
        $this->assertTrue($this->getContainerLogHandler()->hasWarning('A warning message with [hidden] secret.'));
        $this->assertTrue($this->getContainerLogHandler()->hasError('An error message.'));
        $this->assertTrue($this->getContainerLogHandler()->hasCritical('A critical example.'));
        $this->assertTrue($this->getContainerLogHandler()->hasInfoThatContains('Client finished'));
    }

    public function testGelfLogTcp()
    {
        $script = [
            'import subprocess',
            'import sys',
            'subprocess.call([sys.executable, "-m", "pip", "install", "--disable-pip-version-check", "pygelf"])',
            'import logging',
            'from pygelf import GelfTcpHandler',
            'import os',
            'logging.basicConfig(level=logging.DEBUG)',
            'logger = logging.getLogger()',
            'logger.removeHandler(logging.getLogger().handlers[0])',
            'logger.addHandler(GelfTcpHandler(host=os.getenv("KBC_LOGGER_ADDR"), port=int(os.getenv("KBC_LOGGER_PORT"))))',
            'logging.debug("A debug message.")',
            'logging.info("An info message.")',
            'logging.warning("A warning message with secure secret.")',
            'logging.error("An error message.")',
            'logging.critical("A critical example.")',
            'print("Client finished")',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $this->assertEquals('', $err);
        $this->assertContains('Client finished', $out);

        $records = $this->getLogHandler()->getRecords();
        $this->assertGreaterThan(0, count($records));
        $records = $this->getContainerLogHandler()->getRecords();
        $this->assertEquals(6, count($records));
        $this->assertTrue($this->getContainerLogHandler()->hasDebug('A debug message.'));
        $this->assertTrue($this->getContainerLogHandler()->hasInfo('An info message.'));
        $this->assertTrue($this->getContainerLogHandler()->hasWarning('A warning message with [hidden] secret.'));
        $this->assertTrue($this->getContainerLogHandler()->hasError('An error message.'));
        $this->assertTrue($this->getContainerLogHandler()->hasCritical('A critical example.'));
        $this->assertTrue($this->getContainerLogHandler()->hasInfoThatContains('Client finished'));
    }

    public function testGelfLogHttp()
    {
        $script = [
            'import subprocess',
            'import sys',
            'subprocess.call([sys.executable, "-m", "pip", "install", "--disable-pip-version-check", "pygelf"])',
            'import logging',
            'from pygelf import GelfHttpHandler',
            'import os',
            'logging.basicConfig(level=logging.DEBUG)',
            'logger = logging.getLogger()',
            'logger.removeHandler(logging.getLogger().handlers[0])',
            'logger.addHandler(GelfHttpHandler(host=os.getenv("KBC_LOGGER_ADDR"), port=int(os.getenv("KBC_LOGGER_PORT")), compress=False))',
            'logging.debug("A debug message.")',
            'logging.info("An info message.")',
            'logging.warning("A warning message with secure secret.")',
            'logging.error("An error message.")',
            'logging.critical("A critical example.")',
            'print("Client finished")',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'http';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $this->assertEquals('', $err);
        $this->assertContains('Client finished', $out);

        $records = $this->getLogHandler()->getRecords();
        $this->assertGreaterThan(0, count($records));
        $records = $this->getContainerLogHandler()->getRecords();
        $this->assertEquals(6, count($records));
        $this->assertTrue($this->getContainerLogHandler()->hasDebug('A debug message.'));
        $this->assertTrue($this->getContainerLogHandler()->hasInfo('An info message.'));
        $this->assertTrue($this->getContainerLogHandler()->hasWarning('A warning message with [hidden] secret.'));
        $this->assertTrue($this->getContainerLogHandler()->hasError('An error message.'));
        $this->assertTrue($this->getContainerLogHandler()->hasCritical('A critical example.'));
        $this->assertTrue($this->getContainerLogHandler()->hasInfoThatContains('Client finished'));
    }

    public function testGelfLogInvalid()
    {
        /* install a broken version of pygelf which does not sent required 'host' field
        and check that it is handled gracefully. */
        $script = [
            'import subprocess',
            'import sys',
            'subprocess.call([sys.executable, "-m", "pip", "install", "--disable-pip-version-check", "pygelf==0.3.1"])',
            'import logging',
            'import pygelf',
            'import os',
            'logging.basicConfig(level=logging.INFO)',
            'logging.getLogger().removeHandler(logging.getLogger().handlers[0])',
            'pygelf_handler = pygelf.GelfTcpHandler(host=os.getenv("KBC_LOGGER_ADDR"), port=os.getenv("KBC_LOGGER_PORT"), debug=False)',
            'logging.getLogger().addHandler(pygelf_handler)',
            'logging.info("A sample info message (pygelf)")',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
            self::fail("Must raise error");
        } catch (ApplicationException $e) {
            self::assertContains('Host parameter is missing from GELF message', $e->getMessage());
        }
    }

    public function testGelfLogInvalidMessage()
    {
        $script = [
            'import subprocess',
            'import sys',
            'subprocess.call([sys.executable, "-m", "pip", "install", "--disable-pip-version-check", "logging_gelf"])',
            'import logging',
            'import logging_gelf.handlers',
            'import logging_gelf.formatters',
            'import os',
            'logger = logging.getLogger()',
            'logging.basicConfig(level=logging.INFO)',
            'logging.getLogger().removeHandler(logging.getLogger().handlers[0])',
            'logging_gelf_handler = logging_gelf.handlers.GELFTCPSocketHandler(host=os.getenv("KBC_LOGGER_ADDR"), port=int(os.getenv("KBC_LOGGER_PORT")))',
            '#logging_gelf_handler.setFormatter(logging_gelf.formatters.GELFFormatter(null_character=True))',
            'logger.addHandler(logging_gelf_handler)',
            'logging.info("A sample info message (invalid)\\x00")',
            'logging.warning("A sample warning message (invalid)\\x00")',
            'print("Client finished")',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $records = $this->getLogHandler()->getRecords();
        $this->assertGreaterThan(0, count($records));
        $this->assertEquals('', $err);
        $this->assertContains("Client finished", $out);
        $records = $this->getContainerLogHandler()->getRecords();
        $this->assertEquals(3, count($records));
        $this->assertTrue($this->getContainerLogHandler()->hasInfoThatContains('Client finished'));
        $this->assertTrue($this->getContainerLogHandler()->hasError('Invalid message: A sample info message (invalid)'));
        $this->assertTrue($this->getContainerLogHandler()->hasError('Invalid message: A sample warning message (invalid)'));
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
        $this->assertEquals('[hidden] message', $error[3]);
        $this->assertNotEmpty($exception);
        $this->assertArrayHasKey('file', $exception['results']);
        $this->assertEquals('/src/TcpClient.php', $exception['results']['file']);
        $this->assertArrayHasKey('full_message', $exception['results']);
        $this->assertEquals("Exception: Test exception (0)\n\n#0 {main}\n", $exception['results']['full_message']);
        $this->assertArrayHasKey('several', $structure['results']['_structure']['with']);
        $this->assertEquals('[hidden]', $structure['results']['_structure']['with']['several']);
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

    public function testGelfLogApplicationError()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getGelfImageConfiguration();
        $imageConfiguration['data']['definition']['uri'] = 'keboola/gelf-test-client';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $imageConfiguration['data']['definition']['build_options']['entry_point'] = 'php /data/test.php';
        $handler = new TestHandler();
        $containerHandler = new TestHandler();
        $dataDir = $this->createScript(
            $temp,
            '<?php
require_once "/src/vendor/autoload.php";
$transport = new Gelf\Transport\TcpTransport(getenv("KBC_LOGGER_ADDR"), getenv("KBC_LOGGER_PORT"));
$publisher = new Gelf\Publisher();
$publisher->addTransport($transport);
$logger = new Gelf\Logger($publisher);
$logger->info("Info message.");
$logger->error("My Error message.");
exit(2);'
        );
        $container = $this->getContainerDummyLogger(
            $imageConfiguration,
            $dataDir,
            $handler,
            $containerHandler
        );
        try {
            $container->run();
        } catch (ApplicationException $e) {
            $this->assertContains("My Error message", $e->getMessage());
        }

        $records = $handler->getRecords();
        $this->assertGreaterThan(0, count($records));
        $records = $containerHandler->getRecords();
        $this->assertEquals(2, count($records));
        $this->assertTrue($containerHandler->hasInfo("Info message."));
        $this->assertTrue($containerHandler->hasError("My Error message."));
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
sleep(2);
print "\n"; // test an empty message
sleep(2);
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
        $this->assertCount(3, $events);
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
        $this->assertCount(1, $error);
        sort($error);
        $this->assertEquals("first message to stderr\nsecond message to stderr", $error[0]);
        sort($info);
        $this->assertCount(2, $info);
        $this->assertEquals("first message to stdout", $info[0]);
        $this->assertEquals("second message to stdout", $info[1]);
    }

    public function testLogApplicationError()
    {
        $imageConfiguration = $this->getImageConfiguration();
        $temp = new Temp('docker');
        $dataDir = $this->createScript(
            $temp,
            '<?php
echo "message to stdout\n";
error_log("message to stderr\n");
exit(2);'
        );
        $sapiService = self::$kernel->getContainer()->get('syrup.storage_api');
        $container = $this->getContainerStorageLogger($sapiService, $imageConfiguration, $dataDir);
        try {
            $container->run();
        } catch (ApplicationException $e) {
            self::assertContains('message to stderr', $e->getMessage());
            self::assertContains('message to stderr', $e->getData()['errorOutput']);
            self::assertContains('message to stdout', $e->getData()['output']);
        }

        sleep(2); // give storage a little timeout to realize that events are in
        $events = $sapiService->getClient()->listEvents(
            ['component' => 'dummy-testing', 'runId' => $sapiService->getClient()->getRunId()]
        );
        self::assertCount(1, $events);
        self::assertEquals("message to stdout", $events[0]['message']);
    }

    public function testLogTimeout()
    {
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['process_timeout'] = 10;
        $temp = new Temp('docker');
        $dataDir = $this->createScript(
            $temp,
            '<?php
echo "message to stdout\n";
error_log("message to stderr\n");
sleep(15);
exit(2);'
        );
        $sapiService = self::$kernel->getContainer()->get('syrup.storage_api');
        $container = $this->getContainerStorageLogger($sapiService, $imageConfiguration, $dataDir);
        try {
            $container->run();
            self::fail("Must raise user exception");
        } catch (UserException $e) {
            self::assertContains('container exceeded the timeout of', $e->getMessage());
            self::assertContains('message to stderr', $e->getData()['errorOutput']);
            self::assertContains('message to stdout', $e->getData()['output']);
        }

        sleep(2); // give storage a little timeout to realize that events are in
        $events = $sapiService->getClient()->listEvents(
            ['component' => 'dummy-testing', 'runId' => $sapiService->getClient()->getRunId()]
        );
        self::assertCount(1, $events);
        self::assertEquals("message to stdout", $events[0]['message']);
    }

    public function testRunnerLogs()
    {
        $imageConfiguration = $this->getImageConfiguration();

        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();
        $serviceContainer = $kernel->getContainer();

        /** @var ObjectEncryptor $encryptor */
        $encryptor = self::$kernel->getContainer()->get('docker_bundle.object_encryptor_factory')->getEncryptor();
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

        $image = ImageFactory::getImage(
            $encryptor,
            $logService->getLog(),
            new Component($imageConfiguration),
            new Temp(),
            true
        );
        $image->prepare([]);
        $logService->setVerbosity($image->getSourceComponent()->getLoggerVerbosity());
        $logService->getLog()->notice("Test Notice");
        $logService->getLog()->error("Test Error");
        $logService->getLog()->info("Test Info");
        $logService->getLog()->warn("Test Warn");
        $logService->getLog()->debug("Test Debug");
        $logService->getLog()->warn('');

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
