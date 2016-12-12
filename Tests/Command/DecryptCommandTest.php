<?php
/**
 * Created by Ondrej Hlavacek <ondrej.hlavacek@keboola.com>
 * Date: 16/10/15
 */

namespace Keboola\DockerBundle\Tests\Command;

use Keboola\DockerBundle\Command\DecryptCommand;
use Keboola\Syrup\Exception\UserException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DecryptCommandTest extends WebTestCase
{
    /**
     * @var Application
     */
    private $application;

    protected function setUp()
    {
        parent::setUp();
        self::$kernel = static::createKernel();
        self::$kernel->boot();
        $this->application = new Application(self::$kernel);
        $this->application->add(new DecryptCommand());
    }

    public function testDecryptGeneric()
    {
        $encryptor = self::$kernel->getContainer()->get("syrup.object_encryptor");
        /** @var DecryptCommand $command */
        $command = $this->application->find('docker:decrypt');
        $command->setContainer(self::$kernel->getContainer());
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'string' => $encryptor->encrypt("test"),
            '-p' => null,
            '-c' => null,
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals(trim("test"), trim($commandTester->getDisplay()));
    }

    public function testDecryptGenericFail()
    {
        /** @var DecryptCommand $command */
        $command = $this->application->find('docker:decrypt');
        $command->setContainer(self::$kernel->getContainer());
        $commandTester = new CommandTester($command);
        try {
            $commandTester->execute([
                'string' => "test",
                '-p' => null,
                '-c' => null,
            ]);
            $this->fail("Attempting to decrypt invalid value must fail.");
        } catch (UserException $e) {
            $this->assertContains('is not an encrypted value', $e->getMessage());
        }
    }

    public function testDecryptComponentSpecific()
    {
        $cryptoWrapper = self::$kernel->getContainer()->get("syrup.encryption.component_project_wrapper");
        $cryptoWrapper->setProjectId("123");
        $cryptoWrapper->setComponentId("dummy");
        $encryptor = self::$kernel->getContainer()->get("syrup.object_encryptor");
        /** @var DecryptCommand $command */
        $command = $this->application->find('docker:decrypt');
        $command->setContainer(self::$kernel->getContainer());
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'string' => $encryptor->encrypt("test"),
            '-p' => "123",
            '-c' => "dummy",
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals(trim("test"), trim($commandTester->getDisplay()));
    }

    public function testDecryptComponentSpecificFail()
    {
        /** @var DecryptCommand $command */
        $command = $this->application->find('docker:decrypt');
        $command->setContainer(self::$kernel->getContainer());
        $commandTester = new CommandTester($command);
        try {
            $commandTester->execute([
                'string' => "test",
                '-p' => "123",
                '-c' => "dummy",
            ]);
            $this->fail("Attempting to decrypt invalid value must fail.");
        } catch (UserException $e) {
            $this->assertContains('is not an encrypted value', $e->getMessage());
        }
    }
}
