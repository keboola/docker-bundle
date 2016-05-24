<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\Csv\CsvFile;
use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Service\ComponentsService;
use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Job\Executor;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Exception;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Job\Metadata\Job;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class LoggerTests extends KernelTestCase
{
    private function getImageConfiguration()
    {
        return [
            "definition" => [
                "type" => "builder",
                "uri" => "quay.io/keboola/docker-base-php56:0.0.2",
                "build_options" => [
                    "repository" => [
                        "uri" => "https://github.com/keboola/docker-demo-app.git",
                        "type" => "git"
                    ],
                    "commands" => [],
                    "entry_point" => "php /data/test.php"
                ],
            ]
        ];
    }

    private function getContainer($imageConfig, $dataDir)
    {
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $containerLog = new ContainerLogger("null");
        $log->pushHandler(new NullHandler());
        $image = Image::factory($encryptor, $log, $imageConfig);

        $container = new Container($image, $log, $containerLog);
        $container->setDataDir($dataDir);
        return $container;
    }


    private function getGelfImageConfiguration()
    {
        return [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/gelf-test-client"
            ],
            "configuration_format" => "json",
            "logging" => [
                "type" => "gelf",
                "gelf_server_type" => "udp"
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

    public function testLogStreamingOn()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration["streaming_logs"] = true;
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $handler = new TestHandler();
        $log->pushHandler($handler);
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log, $containerLog);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript(
            $temp,
            '<?php
echo "first message to stdout\n";
file_put_contents("php://stderr", "first message to stderr\n");
sleep(5);
error_log("second message to stderr\n");
print "second message to stdout\n";'
        );
        $container->setDataDir($dataDir);

        $process = $container->run("testsuite", []);
        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $this->assertEquals("first message to stdout\nsecond message to stdout\n", $out);
        $this->assertEquals("first message to stderr\nsecond message to stderr\n\n", $err);
        $this->assertTrue($handler->hasErrorRecords());
        $this->assertTrue($handler->hasInfoRecords());
        $records = $handler->getRecords();
        $this->assertGreaterThan(4, count($records));
        $this->assertTrue($handler->hasInfo("first message to stdout\n"));
        $this->assertTrue($handler->hasInfo("second message to stdout\n"));
        $this->assertTrue($handler->hasError("first message to stderr\n"));
        $this->assertTrue($handler->hasError("second message to stderr\n\n"));
    }

    public function testLogStreamingOff()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration["streaming_logs"] = false;
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $handler = new TestHandler();
        $log->pushHandler($handler);
        $containerLog = new ContainerLogger("null");
        $containerLog->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log, $containerLog);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript(
            $temp,
            '<?php
            echo "first message to stdout\n";
            file_put_contents("php://stderr", "first message to stderr\n");
            sleep(5);
            error_log("second message to stderr\n");
            print "second message to stdout\n";'
        );
        $container->setDataDir($dataDir);

        $process = $container->run("testsuite", []);
        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $this->assertEquals("first message to stdout\nsecond message to stdout\n", $out);
        $this->assertEquals("first message to stderr\nsecond message to stderr\n\n", $err);
        $this->assertFalse($handler->hasErrorRecords());
        $this->assertFalse($handler->hasInfoRecords());
        $this->assertFalse($handler->hasInfo('first message to stdout'));
        $this->assertFalse($handler->hasInfo('second message to stdout'));
        $this->assertFalse($handler->hasInfo('first message to stderr'));
        $this->assertFalse($handler->hasInfo('second message to stderr'));
    }

    public function testGelfLogUdp()
    {
        //keboola.docker-log-test
        $temp = new Temp('docker');
        $imageConfiguration = $this->getGelfImageConfiguration();
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $handler = new TestHandler();
        $log->pushHandler($handler);
        $containerLog = new ContainerLogger("null");
        $containerHandler = new TestHandler();
        $containerLog->pushHandler($containerHandler);

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log, $containerLog);
        $container->setId("dummy-testing");
        $container->setDataDir($temp->getTmpFolder());

        $process = $container->run("testsuite" . uniqid(), []);
        $out = $process->getOutput();
        $err = $process->getErrorOutput();
        $records = $handler->getRecords();
        $this->assertGreaterThan(0, count($records));
        $this->assertEquals('', $err);
        $this->assertEquals('Client finished', $out);
        $records = $containerHandler->getRecords();
        $this->assertEquals(7, count($records));
        $this->assertTrue($containerHandler->hasDebug("A debug message."));
        $this->assertTrue($containerHandler->hasAlert("An alert message"));
        $this->assertTrue($containerHandler->hasEmergency("Exception example"));
        $this->assertTrue($containerHandler->hasAlert("Structured message"));
        $this->assertTrue($containerHandler->hasWarning("A warning message."));
        $this->assertTrue($containerHandler->hasInfoRecords());
        $this->assertTrue($containerHandler->hasError("Error message."));
    }
}