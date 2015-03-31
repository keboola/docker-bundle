<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Temp\Temp;
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
        $image = Image::factory($imageConfiguration);

        $container = new Container($image);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo "Hello from Keboola Space Program";');
        $container->setDataDir($dataDir);
        $container->setEnvironmentVariables(['command' => '/data/test.php']);
        $process = $container->run();

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
        $image = Image::factory($imageConfiguration);

        $container = new Container($image);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php this would be a parse error');
        $container->setDataDir($dataDir);
        $container->setEnvironmentVariables(['command' => '/data/test.php']);

        try {
            $container->run();
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
        $image = Image::factory($imageConfiguration);

        $container = new Container($image);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo "graceful error"; exit(1);');
        $container->setDataDir($dataDir);
        $container->setEnvironmentVariables(['command' => '/data/test.php']);

        try {
            $container->run();
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
        $image = Image::factory($imageConfiguration);

        $container = new Container($image);
        $container->setId("keboola/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php echo "less graceful error"; exit(255);');
        $container->setDataDir($dataDir);
        $container->setEnvironmentVariables(['command' => '/data/test.php']);

        try {
            $container->run();
            $this->fail("Must raise an exception");
        } catch (ApplicationException $e) {
            $this->assertContains('graceful error', $e->getMessage());
        }
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
        $image = Image::factory($imageConfiguration);
        $image->setProcessTimeout(1);

        $container = new Container($image);
        $container->setId("odinuv/docker-php-test");
        $dataDir = $this->createScript($temp, '<?php sleep(10);');
        $container->setDataDir($dataDir);
        $container->setEnvironmentVariables(['command' => '/data/test.php']);

        try {
            $container->run();
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
        $image = Image::factory($imageConfiguration);

        $container = new Container($image);
        try {
            $container->run();
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
        $image = Image::factory($imageConfiguration);
        $dataDir = $this->createScript($temp, '<?php sleep(10);');

        $container = new Container($image);
        $container->setDataDir($dataDir);
        try {
            $container->run();
            $this->fail("Must raise an exception for invalid immage");
        } catch (ApplicationException $e) {
            $this->assertContains('Cannot pull', $e->getMessage());
        }
    }
}
