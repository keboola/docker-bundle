<?php

namespace Keboola\DockerBundle\Tests\Docker\Container;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Tests\BaseContainerTest;
use Keboola\StorageApi\Event;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;

class LoggerTest extends BaseContainerTest
{
    public function testLogs()
    {
        $script = [
            'import sys',
            'print("WARNING: Your kernel does not support swap limit capabilities or the ' .
                'cgroup is not mounted. Memory limited without swap.", file=sys.stderr)',
                // the above one is filtered in WtfWarningFilter and does not appear in the result at all
            'print("What is public is not secure", file=sys.stdout)',
            'print("Message to stderr isAlsoSecure", file=sys.stderr)',
        ];
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $process = $container->run();
        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        self::assertContains('What is public is not [hidden]', $out);
        self::assertContains('Message to stderr [hidden]', $err);
        self::assertTrue($this->getLogHandler()->hasNoticeRecords());
        self::assertTrue($this->getLogHandler()->hasErrorRecords());
        self::assertTrue($this->getContainerLogHandler()->hasInfoThatContains('What is public is not [hidden]'));
        self::assertTrue($this->getContainerLogHandler()->hasErrorThatContains('Message to stderr [hidden]'));
        self::assertFalse($this->getContainerLogHandler()->hasErrorThatContains('Your kernel does not support swap'));
        $records = $this->getContainerLogHandler()->getRecords();
        self::assertGreaterThanOrEqual(2, count($records));
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
        $imageConfiguration['features'] = ['container-root-user'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        self::assertEquals('', $err);
        self::assertContains('Client finished', $out);

        $records = $this->getLogHandler()->getRecords();
        self::assertGreaterThan(0, count($records));
        $records = $this->getContainerLogHandler()->getRecords();
        self::assertEquals(6, count($records));
        self::assertTrue($this->getContainerLogHandler()->hasDebug('A debug message.'));
        self::assertTrue($this->getContainerLogHandler()->hasInfo('An info message.'));
        self::assertTrue($this->getContainerLogHandler()->hasWarning('A warning message with [hidden] secret.'));
        self::assertTrue($this->getContainerLogHandler()->hasError('An error message.'));
        self::assertTrue($this->getContainerLogHandler()->hasCritical('A critical example.'));
        self::assertTrue($this->getContainerLogHandler()->hasInfoThatContains('Client finished'));
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
        $imageConfiguration['features'] = ['container-root-user'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        self::assertEquals('', $err);
        self::assertContains('Client finished', $out);

        $records = $this->getLogHandler()->getRecords();
        self::assertGreaterThan(0, count($records));
        $records = $this->getContainerLogHandler()->getRecords();
        self::assertEquals(6, count($records));
        self::assertTrue($this->getContainerLogHandler()->hasDebug('A debug message.'));
        self::assertTrue($this->getContainerLogHandler()->hasInfo('An info message.'));
        self::assertTrue($this->getContainerLogHandler()->hasWarning('A warning message with [hidden] secret.'));
        self::assertTrue($this->getContainerLogHandler()->hasError('An error message.'));
        self::assertTrue($this->getContainerLogHandler()->hasCritical('A critical example.'));
        self::assertTrue($this->getContainerLogHandler()->hasInfoThatContains('Client finished'));
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
        $imageConfiguration['features'] = ['container-root-user'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        self::assertEquals('', $err);
        self::assertContains('Client finished', $out);

        $records = $this->getLogHandler()->getRecords();
        self::assertGreaterThan(0, count($records));
        $records = $this->getContainerLogHandler()->getRecords();
        self::assertEquals(6, count($records));
        self::assertTrue($this->getContainerLogHandler()->hasDebug('A debug message.'));
        self::assertTrue($this->getContainerLogHandler()->hasInfo('An info message.'));
        self::assertTrue($this->getContainerLogHandler()->hasWarning('A warning message with [hidden] secret.'));
        self::assertTrue($this->getContainerLogHandler()->hasError('An error message.'));
        self::assertTrue($this->getContainerLogHandler()->hasCritical('A critical example.'));
        self::assertTrue($this->getContainerLogHandler()->hasInfoThatContains('Client finished'));
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
        $imageConfiguration['features'] = ['container-root-user'];
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
        $imageConfiguration['features'] = ['container-root-user'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $process = $container->run();

        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $records = $this->getLogHandler()->getRecords();
        self::assertGreaterThan(0, count($records));
        self::assertEquals('', $err);
        self::assertContains("Client finished", $out);
        $records = $this->getContainerLogHandler()->getRecords();
        self::assertEquals(3, count($records));
        self::assertTrue($this->getContainerLogHandler()->hasInfoThatContains('Client finished'));
        self::assertTrue($this->getContainerLogHandler()->hasErrorThatContains(
            'Invalid message: Cannot parse JSON data in event: "Syntax error". Data: "A sample info message (invalid)".'
        ));
        self::assertTrue($this->getContainerLogHandler()->hasErrorThatContains(
            'Invalid message: Cannot parse JSON data in event: "Syntax error". Data: "A sample warning message (invalid)".'
        ));
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
        $imageConfiguration['features'] = ['container-root-user'];
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $this->setCreateEventCallback(
            function (Event $event) use (&$error, &$warn, &$info) {
                if ($event->getType() == 'error') {
                    $error[] = $event->getMessage();
                }
                if ($event->getType() == 'info') {
                    $info[] = $event->getMessage();
                }
                if ($event->getType() == 'warn') {
                    $warn[] = $event->getMessage();
                }
                return true;
            }
        );
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $container->run();

        self::assertCount(1, $warn);
        self::assertEquals('A warning message with [hidden] secret.', $warn[0]);
        self::assertCount(2, $info);
        sort($info);
        self::assertEquals('An info message.', $info[0]);
        self::assertContains('Client finished', $info[1]);
        sort($error);
        self::assertCount(2, $error);
        self::assertEquals('An error message.', $error[0]);
        self::assertEquals('Application error', $error[1]);
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
            '       record.structure = {"foo": "bar", "baz": "isAlsoSecure"}',
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
        $imageConfiguration['features'] = ['container-root-user'];
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

        $error = [];
        $info = [];
        $warn = [];
        /** @var Event $structured */
        $structured = null;
        $this->setCreateEventCallback(
            function (Event $event) use (&$error, &$warn, &$info, &$structured) {
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
                return true;
            }
        );
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
            self::fail('Must raise exception');
        } catch (UserException $e) {
            self::assertContains('Exception example', $e->getMessage());
        }
        self::assertCount(1, $warn);
        self::assertEquals('A warning message with [hidden] secret.', $warn[0]);
        self::assertCount(3, $info);
        sort($info);
        self::assertEquals('A debug message.', $info[0]);
        self::assertEquals('An info message.', $info[1]);
        self::assertContains('Installing collected packages: pygelf', $info[2]);
        sort($error);
        self::assertCount(3, $error);
        self::assertEquals('A critical example.', $error[0]);
        self::assertEquals('An error message.', $error[1]);
        self::assertContains('Exception example', $error[2]);
        self::assertNotEmpty($structured->getResults());
        self::assertArrayHasKey('_file', $structured->getResults());
        self::assertArrayHasKey('_structure', $structured->getResults());
        self::assertArrayHasKey('_line', $structured->getResults());
        self::assertEquals('<string>', $structured->getResults()['_file']);
        self::assertEquals('20', $structured->getResults()['_line']);
        self::assertEquals(['foo' => 'bar', 'baz' => '[hidden]'], $structured->getResults()['_structure']);
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
        $imageConfiguration['features'] = ['container-root-user'];
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
        self::assertCount(0, $records);
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
        $imageConfiguration['features'] = ['container-root-user'];
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
        } catch (ApplicationException $e) {
            self::assertContains('failed: (2) Script file /data/script.py', $e->getMessage());
        }

        $records = $this->getContainerLogHandler()->getRecords();
        self::assertGreaterThan(0, count($records));
        $records = $this->getContainerLogHandler()->getRecords();
        self::assertGreaterThan(2, count($records));
        self::assertTrue($this->getContainerLogHandler()->hasInfoThatContains('Info message.'));
        self::assertTrue($this->getContainerLogHandler()->hasError('My Error message.'));
    }

    public function testStdoutVerbosity()
    {
        $script = [
            'import sys',
            'print("first message to stdout", file=sys.stdout)',
            'print("first message to stderr", file=sys.stderr)',
        ];
        $infoText = '';
        $errors = [];
        $this->setCreateEventCallback(
            function (Event $event) use (&$infoText, &$errors) {
                if ($event->getType() == 'info') {
                    $infoText .= $event->getMessage();
                }
                if ($event->getType() == 'error') {
                    $errors[] = $event->getMessage();
                }
                return true;
            }
        );
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $container->run();
        self::assertEquals(1, count($errors));
        self::assertContains('first message to stdout', $infoText);
        self::assertEquals('first message to stderr', $errors[0]);
    }

    public function testEmptyMessage()
    {
        $script = [
            'import sys',
            'print("\n", file=sys.stdout)',
            'print("\n", file=sys.stderr)',
        ];
        $error = [];
        $info = [];
        $this->setCreateEventCallback(
            function (Event $event) use (&$info, &$error) {
                if ($event->getType() == 'error') {
                    $error[] = $event->getMessage();
                }
                if ($event->getType() == 'info') {
                    $info[] = $event->getMessage();
                }
                return true;
            }
        );
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $container->run();
        self::assertEquals(0, count($error));
        self::assertGreaterThan(0, count($info));
    }

    public function testLogApplicationError()
    {
        $script = [
            'import sys',
            'print("first message to stdout", file=sys.stdout)',
            'print("first message to stderr", file=sys.stderr)',
            'sys.exit(2)'
        ];
        $contents = '';
        $this->setCreateEventCallback(
            function (Event $event) use (&$contents) {
                $contents .= $event->getMessage();
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
        self::assertContains('message to stdout', $contents);
        self::assertNotContains('message to stderr', $contents);
    }

    public function testLogUserError()
    {
        $script = [
            'import sys',
            'print("first message to stdout", file=sys.stdout)',
            'print("first message to stderr" * 100000, file=sys.stderr)',
            'sys.exit(1)'
        ];
        $contents = '';
        $this->setCreateEventCallback(
            function (Event $event) use (&$contents) {
                $contents .= $event->getMessage();
                return true;
            }
        );
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        try {
            $container->run();
        } catch (UserException $e) {
            self::assertContains('message to stderr', $e->getMessage());
            self::assertGreaterThan(4000, strlen($e->getMessage()));
            self::assertLessThan(4050, strlen($e->getMessage()));
            self::assertContains('message to stderr', $e->getData()['errorOutput']);
            self::assertContains('message to stdout', $e->getData()['output']);
        }
        self::assertContains('message to stdout', $contents);
        self::assertNotContains('message to stderr', $contents);
    }

    public function testLogTimeout()
    {
        $contents = '';
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
        $this->setCreateEventCallback(
            function (Event $event) use (&$contents) {
                $contents .= $event->getMessage();
                return true;
            }
        );
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
            self::fail('Must raise user exception');
        } catch (UserException $e) {
            self::assertContains('container exceeded the timeout of', $e->getMessage());
            self::assertContains('message to stderr', $e->getData()['errorOutput']);
            self::assertContains('message to stdout', $e->getData()['output']);
        }
        self::assertContains('message to stdout', $contents);
    }

    public function testRunnerLogs()
    {
        $error = [];
        $info = [];
        $warn = [];
        $this->setCreateEventCallback(
            function (Event $event) use (&$error, &$warn, &$info) {
                if ($event->getType() == 'error') {
                    $error[] = $event->getMessage();
                }
                if ($event->getType() == 'warn') {
                    $warn[] = $event->getMessage();
                }
                if ($event->getType() == 'info') {
                    $info[] = $event->getMessage();
                }
                return true;
            }
        );
        $this->getContainer($this->getImageConfiguration(), [], [], false);
        $testHandler = new TestHandler();
        $containerTestHandler = new TestHandler();
        $containerStub = $this->getMockBuilder(\Symfony\Component\DependencyInjection\Container::class)
            ->disableOriginalConstructor()
            ->getMock();
        $containerStub->expects(self::any())
            ->method('get')
            ->will(self::returnValue($this->getStorageApiService()));
        /** @var \Symfony\Component\DependencyInjection\Container $containerStub */
        $sapiHandler = new StorageApiHandler('runner-tests', $containerStub);
        $log = new Logger('runner-tests', [$testHandler, $sapiHandler]);
        $containerLog = new ContainerLogger('container-tests', [$containerTestHandler]);
        $logService = new LoggersService($log, $containerLog, $sapiHandler);
        $logService->getLog()->notice('Test Notice');
        $logService->getLog()->error('Test Error');
        $logService->getLog()->info('Test Info');
        $logService->getLog()->warn('Test Warn');
        $logService->getLog()->debug('Test Debug');
        $logService->getLog()->warn('');

        self::assertCount(1, $error);
        self::assertEquals('Test Error', $error[0]);
        self::assertCount(1, $info);
        self::assertEquals('Test Warn', $warn[0]);
        self::assertCount(1, $warn);
        self::assertEquals('Test Info', $info[0]);
    }
}
