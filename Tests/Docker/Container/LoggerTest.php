<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Container;

use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandlerInterface;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\DockerBundle\Tests\BaseContainerTest;
use Keboola\DockerBundle\Tests\StorageApiHandler;
use Keboola\StorageApi\Event;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
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
        self::assertStringContainsString('What is public is not [hidden]', $out);
        self::assertStringContainsString('Message to stderr [hidden]', $err);
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
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
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
        self::assertStringContainsString('Client finished', $out);

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
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
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
        self::assertStringContainsString('Client finished', $out);

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
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
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
        self::assertStringContainsString('Client finished', $out);

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
        /* install a broken version of pygelf which does not send required 'host' field
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
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            'pygelf_handler = pygelf.GelfTcpHandler(host=os.getenv("KBC_LOGGER_ADDR"), port=os.getenv("KBC_LOGGER_PORT"), debug=False)',
            'logging.getLogger().addHandler(pygelf_handler)',
            'logging.info("A sample info message (pygelf)")',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['logging']['type'] = 'gelf';
        $imageConfiguration['data']['logging']['gelf_server_type'] = 'tcp';
        $imageConfiguration['features'] = ['container-root-user'];
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $container->run();
        self::assertTrue($this->getLogHandler()->hasRecordThatPasses(
            function (array $record) {
                return ($record['message'] === 'Missing required field from event.') &&
                    (array_keys($record['context']) === ['version', 'short_message', 'timestamp', 'level', 'source']) &&
                    ($record['context']['short_message'] === 'A sample info message (pygelf)');
            },
            MonologLogger::NOTICE,
        ));
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
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
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
        self::assertStringContainsString('Client finished', $out);
        $records = $this->getContainerLogHandler()->getRecords();
        self::assertEquals(3, count($records));
        self::assertTrue($this->getContainerLogHandler()->hasInfoThatContains('Client finished'));
        self::assertTrue($this->getContainerLogHandler()->hasErrorThatContains(
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            'Invalid message: Cannot parse JSON data in event: "Syntax error". Data: "A sample info message (invalid)".',
        ));
        self::assertTrue($this->getContainerLogHandler()->hasErrorThatContains(
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            'Invalid message: Cannot parse JSON data in event: "Syntax error". Data: "A sample warning message (invalid)".',
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
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
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

        /** @var string[] $error */
        $error = [];
        /** @var string[] $warn */
        $warn = [];
        /** @var string[] $info */
        $info = [];
        $this->setCreateEventCallback(
            function (Event $event) use (&$error, &$warn, &$info) {
                if ($event->getType() === 'error') {
                    $error[] = (string) $event->getMessage();
                }
                if ($event->getType() === 'info') {
                    $info[] = (string) $event->getMessage();
                }
                if ($event->getType() === 'warn') {
                    $warn[] = (string) $event->getMessage();
                }
                return true;
            },
        );
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        $container->run();

        self::assertCount(1, $warn);
        self::assertEquals('A warning message with [hidden] secret.', $warn[0]);
        self::assertCount(2, $info);
        sort($info);
        self::assertEquals('An info message.', $info[0]);
        self::assertStringContainsString('Client finished', $info[1]);
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
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
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
            MonologLogger::DEBUG => StorageApiHandlerInterface::VERBOSITY_VERBOSE,
            MonologLogger::INFO => StorageApiHandlerInterface::VERBOSITY_VERBOSE,
            MonologLogger::NOTICE => StorageApiHandlerInterface::VERBOSITY_VERBOSE,
            MonologLogger::WARNING => StorageApiHandlerInterface::VERBOSITY_VERBOSE,
            MonologLogger::ERROR => StorageApiHandlerInterface::VERBOSITY_VERBOSE,
            MonologLogger::CRITICAL => StorageApiHandlerInterface::VERBOSITY_VERBOSE,
            MonologLogger::ALERT => StorageApiHandlerInterface::VERBOSITY_VERBOSE,
            MonologLogger::EMERGENCY => StorageApiHandlerInterface::VERBOSITY_VERBOSE,
        ];

        /** @var string[] $error */
        $error = [];
        /** @var string[] $warn */
        $warn = [];
        /** @var string[] $info */
        $info = [];
        /** @var Event $structured */
        $structured = null;
        $this->setCreateEventCallback(
            function (Event $event) use (&$error, &$warn, &$info, &$structured) {
                if ($event->getType() === 'error') {
                    $error[] = $event->getMessage();
                }
                if ($event->getType() === 'info') {
                    $info[] = $event->getMessage();
                }
                if ($event->getType() === 'warn') {
                    $warn[] = $event->getMessage();
                }
                if ($event->getMessage() === 'A critical example.') {
                    $structured = $event;
                }
                return true;
            },
        );
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
            self::fail('Must raise exception');
        } catch (UserException $e) {
            self::assertStringContainsString('Exception example', $e->getMessage());
        }
        self::assertCount(1, $warn);
        self::assertEquals('A warning message with [hidden] secret.', $warn[0]);
        self::assertCount(3, $info);
        sort($info);
        self::assertEquals('A debug message.', $info[0]);
        self::assertEquals('An info message.', $info[1]);
        self::assertStringContainsString('Installing collected packages: pygelf', $info[2]);
        sort($error);
        self::assertCount(3, $error);
        self::assertEquals('A critical example.', $error[0]);
        self::assertEquals('An error message.', $error[1]);
        self::assertStringContainsString('Exception example', $error[2]);
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
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
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
            MonologLogger::DEBUG => StorageApiHandlerInterface::VERBOSITY_NONE,
            MonologLogger::INFO => StorageApiHandlerInterface::VERBOSITY_NONE,
            MonologLogger::NOTICE => StorageApiHandlerInterface::VERBOSITY_NONE,
            MonologLogger::WARNING => StorageApiHandlerInterface::VERBOSITY_NONE,
            MonologLogger::ERROR => StorageApiHandlerInterface::VERBOSITY_NONE,
            MonologLogger::CRITICAL => StorageApiHandlerInterface::VERBOSITY_NONE,
            MonologLogger::ALERT => StorageApiHandlerInterface::VERBOSITY_NONE,
            MonologLogger::EMERGENCY => StorageApiHandlerInterface::VERBOSITY_NONE,
        ];
        $records = [];
        $this->setCreateEventCallback(
            function ($event) use (&$records) {
                $records[] = $event;
                return true;
            },
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
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
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
            self::assertStringContainsString('failed: (2) Script file /data/script.py', $e->getMessage());
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
                if ($event->getType() === 'info') {
                    $infoText .= $event->getMessage();
                }
                if ($event->getType() === 'error') {
                    $errors[] = $event->getMessage();
                }
                return true;
            },
        );
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        $container->run();
        self::assertEquals(1, count($errors));
        self::assertStringContainsString('first message to stdout', $infoText);
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
                if ($event->getType() === 'error') {
                    $error[] = $event->getMessage();
                }
                if ($event->getType() === 'info') {
                    $info[] = $event->getMessage();
                }
                return true;
            },
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
            'sys.exit(2)',
        ];
        $contents = '';
        $this->setCreateEventCallback(
            function (Event $event) use (&$contents) {
                $contents .= $event->getMessage();
                return true;
            },
        );
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        try {
            $container->run();
        } catch (ApplicationException $e) {
            self::assertStringContainsString('message to stderr', $e->getMessage());
            self::assertStringContainsString('message to stderr', $e->getData()['errorOutput']);
            self::assertStringContainsString('message to stdout', $e->getData()['output']);
        }
        self::assertStringContainsString('message to stdout', $contents);
        self::assertStringNotContainsString('message to stderr', $contents);
    }

    public function testLogUserError()
    {
        $script = [
            'import sys',
            'print("first message to stdout", file=sys.stdout)',
            'print("first message to stderr" * 100000, file=sys.stderr)',
            'sys.exit(1)',
        ];
        $contents = '';
        $this->setCreateEventCallback(
            function (Event $event) use (&$contents) {
                $contents .= $event->getMessage();
                return true;
            },
        );
        $container = $this->getContainer($this->getImageConfiguration(), [], $script, true);
        try {
            $container->run();
        } catch (UserException $e) {
            self::assertStringContainsString('message to stderr', $e->getMessage());
            self::assertSame(3999, strlen($e->getMessage()));
            self::assertStringContainsString('message to stderr', $e->getData()['errorOutput']);
            self::assertStringContainsString('message to stdout', $e->getData()['output']);
        }
        self::assertStringContainsString('message to stdout', $contents);
        self::assertStringNotContainsString('message to stderr', $contents);
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
            'sys.exit(2)',
        ];
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration['data']['process_timeout'] = 10;
        $this->setCreateEventCallback(
            function (Event $event) use (&$contents) {
                $contents .= $event->getMessage();
                return true;
            },
        );
        $container = $this->getContainer($imageConfiguration, [], $script, true);
        try {
            $container->run();
            self::fail('Must raise user exception');
        } catch (UserException $e) {
            self::assertStringContainsString('container exceeded the timeout of', $e->getMessage());
            self::assertStringContainsString('message to stderr', $e->getData()['errorOutput']);
            self::assertStringContainsString('message to stdout', $e->getData()['output']);
        }
        self::assertStringContainsString('message to stdout', $contents);
    }

    public function testRunnerLogs()
    {
        $error = [];
        $info = [];
        $warn = [];
        $this->setCreateEventCallback(
            function (Event $event) use (&$error, &$warn, &$info) {
                if ($event->getType() === 'error') {
                    $error[] = $event->getMessage();
                }
                if ($event->getType() === 'warn') {
                    $warn[] = $event->getMessage();
                }
                if ($event->getType() === 'info') {
                    $info[] = $event->getMessage();
                }
                return true;
            },
        );
        $this->getContainer($this->getImageConfiguration(), [], [], false);
        $testHandler = new TestHandler();
        $containerTestHandler = new TestHandler();
        $sapiHandler = new StorageApiHandler('runner-tests', $this->getStorageClientStub());
        $log = new Logger('runner-tests', [$testHandler, $sapiHandler]);
        $containerLog = new ContainerLogger('container-tests', [$containerTestHandler]);
        $logService = new LoggersService($log, $containerLog, $sapiHandler);
        $logService->getLog()->notice('Test Notice');
        $logService->getLog()->error('Test Error');
        $logService->getLog()->info('Test Info');
        $logService->getLog()->warning('Test Warn');
        $logService->getLog()->debug('Test Debug');
        $logService->getLog()->warning('');

        self::assertCount(1, $error);
        self::assertEquals('Test Error', $error[0]);
        self::assertCount(1, $info);
        self::assertEquals('Test Warn', $warn[0]);
        self::assertCount(1, $warn);
        self::assertEquals('Test Info', $info[0]);
    }
}
