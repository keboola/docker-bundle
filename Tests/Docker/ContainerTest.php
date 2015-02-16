<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Container;
use Keboola\DockerBundle\Docker\Image;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class ContainerTest extends \PHPUnit_Framework_TestCase
{

    public function testCreateAndDropDataDir()
    {
        $container = new Container(Image::factory());
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
                "uri" => "keboola/docker-demo"
            )
        );
        $image = Image::factory($imageConfiguration);

        $container = new \Keboola\DockerBundle\Tests\Docker\Mock\Container($image);

        $callback = function() {
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
system:
  image_tag: latest # just an example, latest by default
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
        $this->assertEquals("Processed 2 rows.", trim($process->getOutput()));
        $container->dropDataDir();
    }

    public function testRunCommand()
    {
        $imageConfiguration = array(
            "definition" => array(
                "type" => "dockerhub",
                "uri" => "keboola/docker-demo"
            )
        );
        $image = Image::factory($imageConfiguration);

        $container = new Container($image);
        $container->setId("keboola/demo:latest");
        $container->setDataDir("/tmp");
        $container->setEnvironmentVariables(array("var" => "val"));
        $expected = "sudo docker run --volume='/tmp':/data --memory='64m' --cpu-shares='1024' -e \"'var'='val'\" --rm --name='keboola-demo-latest-name' 'keboola/demo:latest'";
        $this->assertEquals($expected, $container->getRunCommand("name"));
    }
}
