<?php

namespace Keboola\DockerBundle\Tests\Command;

use Keboola\DockerBundle\Command\GarbageCollectCommand;
use Keboola\DockerBundle\Docker\Image\Builder\ImageBuilder;
use Keboola\Temp\Temp;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class GarbageCollectCommandTest extends WebTestCase
{
    public function setUp()
    {
        parent::setUp();
        self::bootKernel();
    }

    private function exec($command)
    {
        $cmd = new Process($command);
        $cmd->setTimeout(null);
        $cmd->mustRun();
        return $cmd->getOutput();
    }

    public function testExecute()
    {
        $temp = new Temp();
        $fs = new Filesystem();
        $keepImages = [];
        $removeImages = [];
        $keepContainers = [];
        $removeContainers = [];

        $this->exec('sudo docker pull alpine:3.6');
        $keepImages = array_merge($keepImages, explode("\n", trim($this->exec('sudo docker images --quiet alpine:3.6'))));

        // prepare dangling image to be removed
        $this->exec('sudo docker pull alpine:3.5');
        $removeImages = array_merge($removeImages, explode("\n", trim($this->exec('sudo docker images --quiet alpine:3.5'))));

        // prepare builder image to be removed
        $removeId = uniqid('test-remove-id');
        $dockerFile = "FROM alpine:3.5\nLABEL " . ImageBuilder::COMMON_LABEL . "\nLABEL com.keboola.docker.runner.test=" . $removeId;
        $fs->dumpFile($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile', $dockerFile);
        $this->exec('cd ' . $temp->getTmpFolder() . ' && sudo docker build . ');
        $removeImages = array_merge($removeImages, explode(
            "\n",
            trim($this->exec(
                "sudo docker images --all --quiet --filter='label=com.keboola.docker.runner.test=" . $removeId . "'"
            ))
        ));

        // make the image dangling
        $this->exec('sudo docker rmi alpine:3.5');

        // prepare container to be removed
        $containerName = uniqid('test-container');
        $this->exec(
            "sudo docker run --entrypoint='/bin/pwd' --name " .
            escapeshellarg($containerName) . " " . end($removeImages)
        );
        $removeContainers = array_merge($removeContainers, explode("\n", trim($this->exec(
            "sudo docker ps --all --quiet --filter 'name=" . escapeshellarg($containerName) . "'"
        ))));

        $deleteTime = time();

        // prepare builder image to be kept
        $keepId = uniqid('test-id');
        $dockerFile = "FROM alpine:3.6\nLABEL " . ImageBuilder::COMMON_LABEL . "\nLABEL com.keboola.docker.runner.test=" . $keepId;
        $fs->dumpFile($temp->getTmpFolder() . DIRECTORY_SEPARATOR . 'Dockerfile', $dockerFile);
        $this->exec('cd ' . $temp->getTmpFolder() . ' && sudo docker build . ');
        $keepImages = array_merge($keepImages, explode(
            "\n",
            trim($this->exec(
                "sudo docker images --all --quiet --filter='label=com.keboola.docker.runner.test=" . $keepId . "'"
            ))
        ));

        // prepare container to be kept
        $containerName = uniqid('test-container');
        $this->exec(
            "sudo docker run --entrypoint='/bin/pwd' --name " .
            escapeshellarg($containerName) . " " . end($keepImages)
        );
        $keepContainers = array_merge($keepContainers, explode("\n", trim($this->exec(
            "sudo docker ps --all --quiet --filter 'name=" . escapeshellarg($containerName) . "'"
        ))));

        // run the garbage collect command
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $application->add(new GarbageCollectCommand());
        $applicationTester = new ApplicationTester($application);
        $applicationTester->run([
            'docker:garbage-collect',
            'timeout' => 60,
            'image-age' => (time() - $deleteTime),
            'container-age' => (time() - $deleteTime),
            'command-timeout' => 20
        ]);

        // check the results
        self::assertContains('Finished', $applicationTester->getDisplay());
        self::assertEquals(0, $applicationTester->getStatusCode());
        $images = explode("\n", $this->exec('sudo docker images --quiet --all'));
        $containers = explode("\n", $this->exec('sudo docker ps --quiet --all'));
        self::assertCount(2, $keepImages);
        self::assertCount(2, $removeImages);
        self::assertCount(1, $keepContainers);
        self::assertCount(1, $removeContainers);
        foreach ($keepImages as $id) {
            self::assertTrue(in_array($id, $images));
        }
        foreach ($removeImages as $id) {
            self::assertFalse(in_array($id, $images));
        }
        foreach ($keepContainers as $id) {
            self::assertTrue(in_array($id, $containers));
        }
        foreach ($removeContainers as $id) {
            self::assertFalse(in_array($id, $containers));
        }
    }

    public function testCommandTimeout()
    {
        // run the garbage collect command
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        /** @var GarbageCollectCommand $mock */
        $application->add(new GarbageCollectCommand());
        $applicationTester = new ApplicationTester($application);
        $applicationTester->run([
            'docker:garbage-collect',
            'timeout' => 1,
            'image-age' => 20,
            'container-age' => 20,
            'command-timeout' => 5
        ]);
        self::assertContains('Timeout reached, terminating', $applicationTester->getDisplay());
        self::assertContains('Finished', $applicationTester->getDisplay());
        self::assertEquals(0, $applicationTester->getStatusCode());
    }
}
