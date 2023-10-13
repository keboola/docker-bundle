<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\RunnerPart2;

use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\Temp\Temp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class WorkingDirectoryTest extends TestCase
{
    public function testWorkingDirectoryTimeout()
    {
        $temp = new Temp();
        $logger = new Logger('test');
        $handler = new TestHandler();
        $logger->pushHandler($handler);
        $workingDir = self::getMockBuilder(WorkingDirectory::class)
            ->setConstructorArgs([$temp->getTmpFolder(), $logger])
            ->setMethods(['getNormalizeCommand'])
            ->getMock();
        $uid = trim(Process::fromShellCommandline('id -u')->mustRun()->getOutput());
        $workingDir->expects(self::exactly(2))
            ->method('getNormalizeCommand')
            ->will(self::onConsecutiveCalls(
                'sleep 130 && sudo chown 0 ' . $temp->getTmpFolder() . ' -R',
                'sudo chown ' . $uid . ' ' . $temp->getTmpFolder() . ' -R',
            ));

        /** @var WorkingDirectory $workingDir */
        $workingDir->createWorkingDir();
        $workingDir->normalizePermissions();
        $workingDir->dropWorkingDir();
        self::assertCount(2, $handler->getRecords());
        self::assertStringContainsString(
            $handler->getRecords()[0]['message'],
            'Normalizing working directory permissions',
        );
        self::assertStringContainsString(
            $handler->getRecords()[1]['message'],
            'Normalizing working directory permissions',
        );
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
        self::assertEquals($tableFile, file_get_contents($workingDir->getDataDir() . '/in/tables/table'));
        self::assertEquals(
            $tableManifestFile,
            file_get_contents($workingDir->getDataDir() . '/in/tables/table.manifest'),
        );
        self::assertFalse(file_exists($workingDir->getDataDir() . '/in/state.json'));
        $workingDir->dropWorkingDir();
    }
}
