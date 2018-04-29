<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\WorkingDirectory;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class WorkingDirectoryTest extends \PHPUnit_Framework_TestCase
{
    public function testWorkingDirectoryTimeout()
    {
        $temp = new Temp();
        $workingDir = $this->getMockBuilder(WorkingDirectory::class)
            ->setConstructorArgs([$temp->getTmpFolder(), new NullLogger()])
            ->setMethods(['getNormalizeCommand'])
            ->getMock();
        $uid = trim((new Process('id -u'))->mustRun()->getOutput());
        $workingDir->method('getNormalizeCommand')
            ->will(self::onConsecutiveCalls(
                'sleep 130 && sudo docker run --rm --volume=' . $temp->getTmpFolder() .
                '/data:/data alpine sh -c \'chown 0 /data -R\'',
                'sudo docker run --rm --volume=' . $temp->getTmpFolder() .
                '/data:/data alpine sh -c \'chown ' . $uid . ' /data -R\''
            ));

        /** @var WorkingDirectory $workingDir */
        $workingDir->createWorkingDir();
        $workingDir->dropWorkingDir();
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
