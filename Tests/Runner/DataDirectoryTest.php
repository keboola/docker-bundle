<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Runner\DataDirectory;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class DataDirectoryTest extends \PHPUnit_Framework_TestCase
{
    public function testDataDirectory()
    {
        $temp = new Temp();
        $dataDir = $this->getMockBuilder(DataDirectory::class)
            ->setConstructorArgs([$temp->getTmpFolder(), new NullLogger()])
            ->setMethods(['getNormalizeCommand'])
            ->getMock();
        $uid = trim((new Process('id -u'))->mustRun()->getOutput());
        $dataDir->method('getNormalizeCommand')
            ->will($this->onConsecutiveCalls(
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sudo docker run --rm --volume=' . $temp->getTmpFolder() . '/data:/data alpine sh -c \'chown ' . $uid . ' /data -R\''
            ));

        /** @var DataDirectory $dataDir */
        $dataDir->createDataDir();
        $dataDir->dropDataDir();
    }

    public function testDataDirectoryTerminate()
    {
        $temp = new Temp();
        $dataDir = $this->getMockBuilder(DataDirectory::class)
            ->setConstructorArgs([$temp->getTmpFolder(), new NullLogger()])
            ->setMethods(['getNormalizeCommand'])
            ->getMock();
        $uid = trim((new Process('id -u'))->mustRun()->getOutput());
        $dataDir->method('getNormalizeCommand')
            ->will($this->onConsecutiveCalls(
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sudo docker run --rm --volume=' . $temp->getTmpFolder() . '/data:/data alpine sh -c \'chown ' . $uid . ' /data -R\''
            ));

        /** @var DataDirectory $dataDir */
        $dataDir->createDataDir();
        try {
            $dataDir->normalizePermissions();
            $this->fail("Too many errors must fail");
        } catch (ApplicationException $e) {
        }
    }

    public function testDataDirectoryTimeout()
    {
        $temp = new Temp();
        $dataDir = $this->getMockBuilder(DataDirectory::class)
            ->setConstructorArgs([$temp->getTmpFolder(), new NullLogger()])
            ->setMethods(['getNormalizeCommand'])
            ->getMock();
        $uid = trim((new Process('id -u'))->mustRun()->getOutput());
        $dataDir->method('getNormalizeCommand')
            ->will($this->onConsecutiveCalls(
                'sleep 70 && sudo docker run --rm --volume=' . $temp->getTmpFolder() . '/data:/data alpine sh -c \'chown 0 /data -R\'',
                'sudo docker run --rm --volume=' . $temp->getTmpFolder() . '/data:/data alpine sh -c \'chown ' . $uid . ' /data -R\''
            ));

        /** @var DataDirectory $dataDir */
        $dataDir->createDataDir();
        $dataDir->dropDataDir();
    }

    public function testDataDirectoryMove()
    {
        $temp = new Temp();
        $dataDir = new DataDirectory($temp->getTmpFolder(), new NullLogger());

        $fs = new Filesystem();
        $tableFile = 'id,name\n10,apples';
        $tableManifestFile = '{"incremental":false}';
        $stateFile = '{"time":{"previousStart":1495580620}}';
        $dataDir->createDataDir();
        $fs->dumpFile($dataDir->getDataDir() . '/out/tables/table', $tableFile);
        $fs->dumpFile($dataDir->getDataDir() . '/out/tables/table.manifest', $tableManifestFile);
        $fs->dumpFile($dataDir->getDataDir() . '/out/state.json', $stateFile);
        $dataDir->moveOutputToInput();
        self::assertEquals($tableFile, file_get_contents($dataDir->getDataDir() . '/in/tables/table'));
        self::assertEquals($tableManifestFile, file_get_contents($dataDir->getDataDir() . '/in/tables/table.manifest'));
        self::assertEquals($stateFile, file_get_contents($dataDir->getDataDir() . '/in/state.json'));
        $dataDir->dropDataDir();
    }
}
