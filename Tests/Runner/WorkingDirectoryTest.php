<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Tests\EventListener\TestLogger;
use Symfony\Component\Process\Process;

class WorkingDirectoryTest extends \PHPUnit_Framework_TestCase
{
    public function testWorkingDirectoryTimeout()
    {
        $temp = new Temp();
        $logger = new Logger('test');
        $handler = new TestHandler();
        $logger->pushHandler($handler);
        $workingDir = $this->getMockBuilder(WorkingDirectory::class)
            ->setConstructorArgs([$temp->getTmpFolder(), $logger])
            ->setMethods(['getNormalizeCommand'])
            ->getMock();
        $uid = trim((new Process('id -u'))->mustRun()->getOutput());
        $workingDir->expects($this->exactly(2))
            ->method('getNormalizeCommand')
            ->will(self::onConsecutiveCalls(
                'sleep 130 && sudo docker run --rm --volume=' . $temp->getTmpFolder() .
                '/data:/data alpine sh -c \'chown 0 /data -R\'',
                'sudo docker run --rm --volume=' . $temp->getTmpFolder() .
                '/data:/data alpine sh -c \'chown ' . $uid . ' /data -R\''
            ));

        /** @var WorkingDirectory $workingDir */
        $workingDir->createWorkingDir();
        $workingDir->normalizePermissions();
        $workingDir->dropWorkingDir();
        self::assertCount(2, $handler->getRecords());
        self::assertContains($handler->getRecords()[0]['message'], 'Normalizing working directory permissions');
        self::assertContains($handler->getRecords()[1]['message'], 'Normalizing working directory permissions');
    }

    public function testWorkingDirectoryMove()
    {
        $temp = new Temp();
        $workingDir = new WorkingDirectory($temp->getTmpFolder(), new NullLogger());

        $fs = new Filesystem();
        $tableFile = 'id,name\n10,apples';
        $tableManifestFile = '{"incremental":false}';
        $stateFile = '{"time":{"previousStart":1495580620}}';
        $workingDir->createWorkingDir();
        $fs->dumpFile($workingDir->getDataDir() . '/out/tables/table', $tableFile);
        $fs->dumpFile($workingDir->getDataDir() . '/out/tables/table.manifest', $tableManifestFile);
        $fs->dumpFile($workingDir->getDataDir() . '/out/state.json', $stateFile);
        $workingDir->moveOutputToInput();
        $this->assertEquals($tableFile, file_get_contents($workingDir->getDataDir() . '/in/tables/table'));
        $this->assertEquals($tableManifestFile, file_get_contents($workingDir->getDataDir() . '/in/tables/table.manifest'));
        $this->assertEquals($stateFile, file_get_contents($workingDir->getDataDir() . '/in/state.json'));
        $workingDir->dropWorkingDir();
    }
}
