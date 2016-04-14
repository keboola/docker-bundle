<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;

class ContainerErrorHandlingTest extends \PHPUnit_Framework_TestCase
{
    private function createScript(Temp $temp, $contents)
    {
        $temp->initRunFolder();
        $dataDir = $temp->getTmpFolder();

        $fs = new Filesystem();
        $fs->dumpFile($dataDir . DIRECTORY_SEPARATOR . 'test.php', $contents);

        return $dataDir;
    }

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

    public function testSuccess()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo "Hello from Keboola Space Program";');
        $container->setDataDir($dataDir);
        $process = $container->run(uniqid(), []);

        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("Hello from Keboola Space Program", trim($process->getOutput()));
    }

    public function testFatal()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php this would be a parse error');
        $container->setDataDir($dataDir);

        try {
            $container->run(uniqid(), []);
            $this->fail("Must raise an exception");
        } catch (ApplicationException $e) {
            $this->assertContains('Parse error', $e->getMessage());
        }
    }


    public function testGraceful()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo "graceful error"; exit(1);');
        $container->setDataDir($dataDir);

        try {
            $container->run(uniqid(), []);
            $this->fail("Must raise an exception");
        } catch (UserException $e) {
            $this->assertContains('graceful error', $e->getMessage());
        }
    }


    public function testLessGraceful()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo "less graceful error"; exit(255);');
        $container->setDataDir($dataDir);

        try {
            $container->run(uniqid(), []);
            $this->fail("Must raise an exception");
        } catch (ApplicationException $e) {
            $this->assertContains('graceful error', $e->getMessage());
        }
    }

    public function testEnvironmentPassing()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo getenv("KBC_TOKENID");');
        $container->setDataDir($dataDir);
        $value = '123 ščř =-\'"321';
        $container->setEnvironmentVariables(['KBC_TOKENID' => $value]);

        $process = $container->run(uniqid(), []);
        $this->assertEquals($value, $process->getOutput());
    }

    public function testTimeout()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $image->setProcessTimeout(1);
        $container = new Container($image, $log);
        $container->setId("odinuv/docker-php-test");

        // set benchmark time
        $dataDir = $this->createScript($temp, '<?php echo "done";');
        $container->setDataDir($dataDir);
        $containerId = uniqid();
        $benchmarkStartTime = time();
        $container->run($containerId, []);
        $benchmarkDuration = time() - $benchmarkStartTime;

        // actual test
        $dataDir = $this->createScript($temp, '<?php sleep(20);');
        $container->setDataDir($dataDir);
        $containerId = uniqid();
        $testStartTime = time();
        try {
            $container->run($containerId, []);
            $this->fail("Must raise an exception");
        } catch (UserException $e) {
            $testDuration = time() - $testStartTime;
            $this->assertContains('timeout', $e->getMessage());
            // test should last longer than benchmark
            $this->assertGreaterThan($benchmarkDuration, $testDuration);
            // test shouldn't last longer than benchmark plus process timeout (plus a safety margin)
            $this->assertLessThan($benchmarkDuration + $image->getProcessTimeout() + 5, $testDuration);
        }
    }


    public function testInvalidDirectory()
    {
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/non-existent"
            ]
        ];
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        try {
            $container->run(uniqid(), []);
            $this->fail("Must raise an exception when data directory is not set.");
        } catch (ApplicationException $e) {
            $this->assertContains('directory', $e->getMessage());
        }
    }


    /**
     *
     */
    public function testInvalidImage()
    {
        $temp = new Temp('docker');
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/non-existent"
            ]
        ];
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $dataDir = $this->createScript($temp, '<?php sleep(10);');
        $container = new Container($image, $log);
        $container->setDataDir($dataDir);
        try {
            $container->run(uniqid(), []);
            $this->fail("Must raise an exception for invalid immage");
        } catch (ApplicationException $e) {
            $this->assertContains('Cannot pull', $e->getMessage());
        }
    }

    public function testLogStreamingOn()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration["memory"] = "64m";
        $imageConfiguration["streaming_logs"] = true;
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $handler = new TestHandler();
        $log->pushHandler($handler);

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId("odinuv/docker-php-test");
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

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId("odinuv/docker-php-test");
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

    /**
     * @expectedException \Keboola\DockerBundle\Exception\OutOfMemoryException
     * @expectedExceptionMessage Out of memory
     */
    public function testOutOfMemory()
    {
        $temp = new Temp('docker');
        $imageConfiguration = $this->getImageConfiguration();
        $imageConfiguration["memory"] = "32m";

        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $handler = new TestHandler();
        $log->pushHandler($handler);

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId("odinuv/docker-php-test");
        $dataDir = $this->createScript(
            $temp,
            '<?php
            $array = [];
            for($i = 0; $i < 1000000; $i++) {
                $array[] = "0123456789";
            }
            print "finished";'
        );
        $container->setDataDir($dataDir);
        $container->run("testsuite", []);
    }
}
