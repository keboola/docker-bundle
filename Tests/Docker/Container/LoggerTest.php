<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Aws\AutoScalingPlans\Exception\AutoScalingPlansException;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilter;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Tests\BaseContainerTest;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Event;
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
        $this->assertTrue($this->getLogHandler()->hasErrorRecords());
        $this->assertTrue($this->getContainerLogHandler()->hasInfoThatContains("What is public is not [hidden]"));
        $this->assertTrue($this->getContainerLogHandler()->hasErrorThatContains("Message to stderr [hidden]"));
        $records = $this->getContainerLogHandler()->getRecords();
        $this->assertGreaterThanOrEqual(2, count($records));
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
        $records = [];
        $this->setCreateEventCallback(
            function ($event) use (&$records) {
                $records[] = $event;
                return true;
            }
        );
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $container->run();

        $this->assertCount(5, $records);
        $error = [];
        $info = [];
        $warn = [];
        /** @var Event[] $records */
        foreach ($records as $event) {
            if ($event->getType() == 'error') {
                $error[] = $event->getMessage();
            }
            if ($event->getType() == 'info') {
                $info[] = $event->getMessage();
            }
            if ($event->getType() == 'warn') {
                $warn[] = $event->getMessage();
            }
        }
        $this->assertCount(1, $warn);
        $this->assertEquals('A warning message with [hidden] secret.', $warn[0]);
        $this->assertCount(2, $info);
        sort($info);
        $this->assertEquals('An info message.', $info[0]);
        $this->assertContains('Client finished', $info[1]);
        sort($error);
        $this->assertCount(2, $error);
        $this->assertEquals('An error message.', $error[0]);
        $this->assertEquals('Application error', $error[1]);
    }

    public function testGelfVerbosityVerbose()
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
            'class ContextFilter(logging.Filter):',
            '   def filter(self, record):',
            '       record.structure = {"foo": "bar", "baz": "secure"}',
            '       return True',
            'logger.addFilter(ContextFilter())',
            'logger.addHandler(GelfTcpHandler(host=os.getenv("KBC_LOGGER_ADDR"), port=int(os.getenv("KBC_LOGGER_PORT")), debug=True, include_extra_fields=True))',
            'logging.debug("A debug message.")',
            'logging.info("An info message.")',
            'logging.warning("A warning message with secure secret.")',
            'logging.error("An error message.")',
            'logging.critical("A critical example.")',
            'raise ValueError("Exception example")',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
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

        $records = [];
        $this->setCreateEventCallback(
            function ($event) use (&$records) {
                $records[] = $event;
                return true;
            }
        );
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
            $this->fail("Must raise exception");
        } catch (UserException $e) {
            $this->assertContains('Exception example', $e->getMessage());
        }
        $this->assertCount(7, $records);
        $error = [];
        $info = [];
        $warn = [];
        $structured = [];
        /** @var Event[] $records */
        foreach ($records as $event) {
            if ($event->getType() == 'error') {
                $error[] = $event->getMessage();
            }
            if ($event->getType() == 'info') {
                $info[] = $event->getMessage();
            }
            if ($event->getType() == 'warn') {
                $warn[] = $event->getMessage();
            }
            if ($event->getMessage() == 'A critical example.') {
                $structured = $event;
            }
        }
        $this->assertCount(1, $warn);
        $this->assertEquals('A warning message with [hidden] secret.', $warn[0]);
        $this->assertCount(3, $info);
        sort($info);
        $this->assertEquals('A debug message.', $info[0]);
        $this->assertEquals('An info message.', $info[1]);
        $this->assertContains('Installing collected packages: pygelf', $info[2]);
        sort($error);
        $this->assertCount(3, $error);
        $this->assertEquals('A critical example.', $error[0]);
        $this->assertEquals('An error message.', $error[1]);
        $this->assertContains('Exception example', $error[2]);
        $this->assertNotEmpty($structured->getResults());
        $this->assertArrayHasKey('_file', $structured->getResults());
        $this->assertArrayHasKey('_structure', $structured->getResults());
        $this->assertArrayHasKey('_line', $structured->getResults());
        $this->assertEquals('<string>', $structured->getResults()['_file']);
        $this->assertEquals('20', $structured->getResults()['_line']);
        $this->assertEquals(['foo' => 'bar', 'baz' => '[hidden]'], $structured->getResults()['_structure']);
    }

    public function testGelfVerbosityNone()
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
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
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
        $records = [];
        $this->setCreateEventCallback(
            function ($event) use (&$records) {
                $records[] = $event;
                return true;
            }
        );
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $container->run();
        $this->assertCount(0, $records);
    }

    public function testGelfLogApplicationError()
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
            'logging.info("Info message.")',
            'logging.error("My Error message.")',
            'sys.exit(2);',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
        } catch (ApplicationException $e) {
            $this->assertContains('Application error', $e->getMessage());
        }

        $records = $this->getContainerLogHandler()->getRecords();
        $this->assertGreaterThan(0, count($records));
        $records = $this->getContainerLogHandler()->getRecords();
        $this->assertGreaterThan(2, count($records));
        $this->assertTrue($this->getContainerLogHandler()->hasInfoThatContains("Info message."));
        $this->assertTrue($this->getContainerLogHandler()->hasError("My Error message."));
    }

    public function testStdoutVerbosity()
    {
        $script = [
            'import sys',
            'print("first message to stdout", file=sys.stdout)',
            'print("first message to stderr", file=sys.stderr)',
        ];
        /** @var Event[] $records */
        $records = [];
        $this->setCreateEventCallback(
            function ($event) use (&$records) {
                $records[] = $event;
                return true;
            }
        );
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $container->run();

        $contents = '';
        $error = [];
        foreach ($records as $record) {
            if ($record->getType() == 'info') {
                $contents .= $record->getMessage();
            }
            if ($record->getType() == 'error') {
                $error[] = $record->getMessage();
            }
        }
        $this->assertEquals(1, count($error));
        $this->assertContains("first message to stdout", $contents);
        $this->assertEquals("first message to stderr", $error[0]);
    }

    public function testEmptyMessage()
    {
        $script = [
            'import sys',
            'print("\n", file=sys.stdout)',
            'print("\n", file=sys.stderr)',
        ];
        /** @var Event[] $records */
        $records = [];
        $this->setCreateEventCallback(
            function ($event) use (&$records) {
                $records[] = $event;
                return true;
            }
        );
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $container->run();
        $error = [];
        $info = [];
        /** @var Event[] $records */
        foreach ($records as $event) {
            if ($event->getType() == 'error') {
                $error[] = $event->getMessage();
            }
            if ($event->getType() == 'info') {
                $info[] = $event->getMessage();
            }
        }
        $this->assertEquals(0, count($error));
        $this->assertGreaterThan(0, count($info));
    }

    public function testLogApplicationError()
    {
        $script = [
            'import sys',
            'print("first message to stdout", file=sys.stdout)',
            'print("first message to stderr", file=sys.stderr)',
            'sys.exit(2)'
        ];
        /** @var Event[] $records */
        $records = [];
        $this->setCreateEventCallback(
            function ($event) use (&$records) {
                $records[] = $event;
                return true;
            }
        );
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        try {
            $container->run();
        } catch (ApplicationException $e) {
            self::assertContains('message to stderr', $e->getMessage());
            self::assertContains('message to stderr', $e->getData()['errorOutput']);
            self::assertContains('message to stdout', $e->getData()['output']);
        }

        self::assertGreaterThanOrEqual(1, count($records));
        $contents = '';
        foreach ($records as $record) {
            $contents .= $record->getMessage();
        }
        self::assertContains("message to stdout", $contents);
        self::assertNotContains("message to stderr", $contents);
    }

    public function testLogTimeout()
    {
        $script = [
            'import sys',
            'import time',
            'print("message to stdout", file=sys.stdout)',
            'print("message to stderr", file=sys.stderr)',
            'time.sleep(15)',
            'sys.exit(2)'
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['process_timeout'] = 10;
        /** @var Event[] $records */
        $records = [];
        $this->setCreateEventCallback(
            function ($event) use (&$records) {
                $records[] = $event;
                return true;
            }
        );
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
            self::fail("Must raise user exception");
        } catch (UserException $e) {
            self::assertContains('container exceeded the timeout of', $e->getMessage());
            self::assertContains('message to stderr', $e->getData()['errorOutput']);
            self::assertContains('message to stdout', $e->getData()['output']);
        }

        $contents = '';
        foreach ($records as $record) {
            $contents .= $record->getMessage();
        }
        self::assertContains("message to stdout", $contents);
    }

    public function testRunnerLogs()
    {
        /** @var Event[] $records */
        $records = [];
        $this->setCreateEventCallback(
            function ($event) use (&$records) {
                $records[] = $event;
                return true;
            }
        );
        $this->getContainer($this->getImageConfiguration(), [], [], false);
        $testHandler = new TestHandler();
        $containerTestHandler = new TestHandler();
        $sapiHandler = new StorageApiHandler('runner-tests', $this->getStorageApiService());
        $log = new Logger('runner-tests', [$testHandler, $sapiHandler]);
        $containerLog = new ContainerLogger('container-tests', [$containerTestHandler]);
        $logService = new LoggersService($log, $containerLog, $sapiHandler);
        $logService->getLog()->notice("Test Notice");
        $logService->getLog()->error("Test Error");
        $logService->getLog()->info("Test Info");
        $logService->getLog()->warn("Test Warn");
        $logService->getLog()->debug("Test Debug");
        $logService->getLog()->warn('');

        $this->assertCount(3, $records);
        $error = [];
        $info = [];
        $warn = [];
        foreach ($records as $event) {
            if ($event->getType() == 'error') {
                $error[] = $event->getMessage();
            }
            if ($event->getType() == 'warn') {
                $warn[] = $event->getMessage();
            }
            if ($event->getType() == 'info') {
                $info[] = $event->getMessage();
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
