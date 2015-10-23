<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\OutOfMemoryException;
use Keboola\Syrup\Encryption\CryptoWrapper;
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


    public function testSuccess()
    {
        $temp = new Temp('docker');
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-php-test"
            ]
        ];
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $container = new Container($image, $log);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo "Hello from Keboola Space Program";');
        $container->setDataDir($dataDir);
        $container->setEnvironmentVariables(['command' => '/data/test.php']);
        $process = $container->run(uniqid());

        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("Hello from Keboola Space Program", trim($process->getOutput()));
    }

    public function testFatal()
    {
        $temp = new Temp('docker');
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-php-test"
            ]
        ];
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $container = new Container($image, $log);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php this would be a parse error');
        $container->setDataDir($dataDir);
        $container->setEnvironmentVariables(['command' => '/data/test.php']);

        try {
            $container->run(uniqid());
            $this->fail("Must raise an exception");
        } catch (ApplicationException $e) {
            $this->assertContains('Parse error', $e->getMessage());
        }
    }


    public function testGraceful()
    {
        $temp = new Temp('docker');
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-php-test"
            ]
        ];
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $container = new Container($image, $log);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo "graceful error"; exit(1);');
        $container->setDataDir($dataDir);
        $container->setEnvironmentVariables(['command' => '/data/test.php']);

        try {
            $container->run(uniqid());
            $this->fail("Must raise an exception");
        } catch (UserException $e) {
            $this->assertContains('graceful error', $e->getMessage());
        }
    }


    public function testLessGraceful()
    {
        $temp = new Temp('docker');
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-php-test"
            ]
        ];
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $container = new Container($image, $log);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo "less graceful error"; exit(255);');
        $container->setDataDir($dataDir);
        $container->setEnvironmentVariables(['command' => '/data/test.php']);

        try {
            $container->run(uniqid());
            $this->fail("Must raise an exception");
        } catch (ApplicationException $e) {
            $this->assertContains('graceful error', $e->getMessage());
        }
    }

    public function testEnvironmentPassing()
    {
        $temp = new Temp('docker');
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-php-test"
            ]
        ];
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $container = new Container($image, $log);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo getenv("KBC_TOKENID");');
        $container->setDataDir($dataDir);
        $value = '123 ščř =-\'"321';
        $container->setEnvironmentVariables(['command' => '/data/test.php', 'KBC_TOKENID' => $value]);

        $process = $container->run(uniqid());
        $this->assertEquals($value, $process->getOutput());
    }

    public function testTimeout()
    {
        $temp = new Temp('docker');
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-php-test"
            ]
        ];
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);
        $image->setProcessTimeout(1);

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $container = new Container($image, $log);
        $container->setId("odinuv/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php sleep(10);');
        $container->setDataDir($dataDir);
        $container->setEnvironmentVariables(['command' => '/data/test.php']);

        try {
            $container->run(uniqid());
            $this->fail("Must raise an exception");
        } catch (UserException $e) {
            $this->assertContains('timeout', $e->getMessage());
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
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $container = new Container($image, $log);
        try {
            $container->run(uniqid());
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
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);
        $dataDir = $this->createScript($temp, '<?php sleep(10);');

        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $container = new Container($image, $log);
        $container->setDataDir($dataDir);
        try {
            $container->run(uniqid());
            $this->fail("Must raise an exception for invalid immage");
        } catch (ApplicationException $e) {
            $this->assertContains('Cannot pull', $e->getMessage());
        }
    }

    public function testLogStreamingOn()
    {
        $temp = new Temp('docker');
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-php-test"
            ],
            "memory" => "64m",
            "streaming_logs" => true
        ];
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);

        $log = new Logger("null");
        $handler = new TestHandler();
        $log->pushHandler($handler);
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
        $container->setEnvironmentVariables(['command' => '/data/test.php']);

        $process = $container->run("testsuite");
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
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-php-test"
            ],
            "streaming_logs" => false
        ];
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);

        $log = new Logger("null");
        $handler = new TestHandler();
        $log->pushHandler($handler);
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
        $container->setEnvironmentVariables(['command' => '/data/test.php']);

        $process = $container->run("testsuite");
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
     * @expectedExceptionMessage Container 'keboola/docker-php-test:latest' failed: Out of memory
     */
    public function testOutOfMemory()
    {
        $temp = new Temp('docker');
        $imageConfiguration = [
            "definition" => [
                "type" => "dockerhub",
                "uri" => "keboola/docker-php-test"
            ],
            "memory" => "10m"
        ];
        $encryptor = new ObjectEncryptor(new CryptoWrapper(hash('sha256', uniqid())));
        $image = Image::factory($encryptor, $imageConfiguration);

        $log = new Logger("null");
        $handler = new TestHandler();
        $log->pushHandler($handler);
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
        $container->setEnvironmentVariables(['command' => '/data/test.php']);
        $container->run("testsuite");
    }
}
