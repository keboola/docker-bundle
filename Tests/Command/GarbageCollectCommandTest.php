<?php

namespace Keboola\DockerBundle\Tests\Command;

use Keboola\DockerBundle\Command\GarbageCollectCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

class GarbageCollectCommandTest extends WebTestCase
{
    public function setUp()
    {
        parent::setUp();
        self::bootKernel();
    }

    public function testExecute()
    {
        // run command
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $application->add(new GarbageCollectCommand());
        $applicationTester = new ApplicationTester($application);
        $applicationTester->run([
            'garbage-collect',
            'timeout' => 10,
        ]);
        self::assertEquals(0, $applicationTester->getStatusCode());
    }
}
