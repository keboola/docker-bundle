<?php

namespace Keboola\DockerBundle\Tests\Command;

use Keboola\DockerBundle\Command\GarbageCollectCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
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
    }

    public function testExecute()
    {
        // prepare some images
        $this->exec('docker pull alpine:3.6');

        // run command
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $application->add(new GarbageCollectCommand());
        $applicationTester = new ApplicationTester($application);
        $applicationTester->run([
            'garbage-collect',
            'timeout' => 10,
            'image-age' => 60,
            'container-age' => 60,
            'command-timeout' => 20
        ]);
        self::assertEquals(0, $applicationTester->getStatusCode());

    }
}
