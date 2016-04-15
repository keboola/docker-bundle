<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Tests\Docker\Mock\ObjectEncryptor;
use Monolog\Handler\NullHandler;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class ContainerTest extends \PHPUnit_Framework_TestCase
{
    public function testCreateAndDropDataDir()
    {
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());
        $dummyConfig = array(
            "definition" => array(
                "type" => "dummy",
                "uri" => "dummy"
            )
        );
        $encryptor = new ObjectEncryptor();
        $container = new Container(Image::factory($encryptor, $log, $dummyConfig), $log);
        $fs = new Filesystem();
        $root = "/tmp/docker/" . uniqid("", true);
        $fs->mkdir($root);
        $container->createDataDir($root);
        $structure = array(
            $root . "/data",
            $root . "/data/in",
            $root . "/data/in/tables",
            $root . "/data/in/files",
            $root . "/data/out",
            $root . "/data/out/tables",
            $root . "/data/out/files"
        );
        $this->assertTrue($fs->exists($structure));

        foreach ($structure as $folder) {
            $fs->touch($folder . "/file");
        }
        $container->dropDataDir();
        $this->assertFalse($fs->exists($root . "/data"));
    }

    public function testRun()
    {
        $imageConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo-app"
            )
        );
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Docker\Mock\Container($image, $log);

        $callback = function () {
            $process = new Process('echo "Processed 2 rows."');
            $process->run();
            return $process;
        };

        $container->setRunMethod($callback);

        $root = "/tmp/docker/" . uniqid("", true);
        $fs = new Filesystem();
        $fs->mkdir($root);
        $container->createDataDir($root);

        $configFile = <<< EOT
storage:
  input:
    tables:
      0:
        source: in.c-main.data
  output:
    tables:
      0:
        source: sliced.csv
        destination: out.c-main.data
parameters:
  primary_key_column: id
  data_column: text
  string_length: 10
EOT;
        file_put_contents($root . "/data/config.yml", $configFile);

        $dataFile = <<< EOF
id,text,some_other_column
1,"Short text","Whatever"
2,"Long text Long text Long text","Something else"
EOF;

        file_put_contents($root . "/data/in/tables/in.c-main.data.csv", $dataFile);

        $process = $container->run();
        $this->assertEquals(0, $process->getExitCode());
        $this->assertContains("Processed 2 rows.", trim($process->getOutput()));
        $container->dropDataDir();
    }

    public function testRunCommand()
    {
        $imageConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo-app",
                "tag" => "master"
            )
        );
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId($image->getFullImageId());
        $container->setDataDir("/tmp");
        $container->setEnvironmentVariables(["var" => "val", "příliš" => 'žluťoučký', "var2" => "weird = '\"value" ]);
        $expected = "sudo timeout --foreground --signal=SIGKILL 3600 sudo docker run --volume='/tmp':/data --memory='64m' --cpu-shares='1024' --net='bridge' -e \"var=val\" -e \"příliš=žluťoučký\" -e \"var2=weird = '\\\"value\" --name='name' 'keboola/docker-demo-app:master'";
        $this->assertEquals($expected, $container->getRunCommand("name"));
    }

    public function testInspectCommand()
    {
        $imageConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo-app"
            )
        );
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId("keboola/docker-demo-app:latest");
        $expected = "sudo docker inspect 'name'";
        $this->assertEquals($expected, $container->getInspectCommand("name"));
    }

    public function testRemoveCommand()
    {
        $imageConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo-app"
            )
        );
        $encryptor = new ObjectEncryptor();
        $log = new Logger("null");
        $log->pushHandler(new NullHandler());

        $image = Image::factory($encryptor, $log, $imageConfiguration);
        $container = new Container($image, $log);
        $container->setId("keboola/docker-demo-app:latest");
        $expected = "sudo docker rm -f 'name'";
        $this->assertEquals($expected, $container->getRemoveCommand("name"));
    }
}
