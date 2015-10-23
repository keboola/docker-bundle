<?php
/**
 * Created by Ondrej Hlavacek <ondrej.hlavacek@keboola.com>
 * Date: 16/10/15
 */

namespace Keboola\DockerBundle\Tests\Command;

use Keboola\DockerBundle\Command\DecryptCommand;
use Keboola\Syrup\Test\CommandTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class DecryptCommandTest extends CommandTestCase
{
    protected function setUp()
    {
        parent::setUp();
        $this->application->add(new DecryptCommand());
    }

    public function testDecryptGeneric()
    {
        $encryptor = self::$kernel->getContainer()->get("syrup.object_encryptor");
        $command = $this->application->find('docker:decrypt');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'string' => $encryptor->encrypt("test"),
            '-p' => null,
            '-c' => null,
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals("test\n", $commandTester->getDisplay());
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage User error: 'test' is not an encrypted value.
     */
    public function testDecryptGenericFail()
    {
        $command = $this->application->find('docker:decrypt');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'string' => "test",
            '-p' => null,
            '-c' => null,
        ]);

    }

    public function testDecryptComponentSpecific()
    {
        $cryptoWrapper = self::$kernel->getContainer()->get("syrup.job_crypto_wrapper");
        $cryptoWrapper->setProjectId("123");
        $cryptoWrapper->setComponentId("dummy");
        $encryptor = self::$kernel->getContainer()->get("syrup.job_object_encryptor");
        $command = $this->application->find('docker:decrypt');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'string' => $encryptor->encrypt("test"),
            '-p' => "123",
            '-c' => "dummy",
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals("test\n", $commandTester->getDisplay());
    }

    /**
     * @expectedException \Keboola\Syrup\Exception\UserException
     * @expectedExceptionMessage User error: 'test' is not an encrypted value.
     */
    public function testDecryptComponentSpecificFail()
    {
        $command = $this->application->find('docker:decrypt');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'string' => "test",
            '-p' => "123",
            '-c' => "dummy",
        ]);

    }
}
