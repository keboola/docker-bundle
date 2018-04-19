<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\DataDirectory;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class DataDirectoryTest extends \PHPUnit_Framework_TestCase
{
    public function testDataDirectoryTimeout()
    {
        $temp = new Temp();
        $dataDir = $this->getMockBuilder(DataDirectory::class)
            ->setConstructorArgs([$temp->getTmpFolder(), new NullLogger()])
            ->setMethods(['getNormalizeCommand'])
            ->getMock();
        $uid = trim((new Process('id -u'))->mustRun()->getOutput());
        $dataDir->method('getNormalizeCommand')
            ->will(self::onConsecutiveCalls(
                'sleep 70 && sudo docker run --rm --volume=' . $temp->getTmpFolder() .
                '/data:/data alpine sh -c \'chown 0 /data -R\'',
                'sudo docker run --rm --volume=' . $temp->getTmpFolder() .
                '/data:/data alpine sh -c \'chown ' . $uid . ' /data -R\''
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
        $this->assertEquals($tableFile, file_get_contents($dataDir->getDataDir() . '/in/tables/table'));
        $this->assertEquals($tableManifestFile, file_get_contents($dataDir->getDataDir() . '/in/tables/table.manifest'));
        $this->assertFalse(file_exists($dataDir->getDataDir() . '/in/state.json'));
        $dataDir->dropDataDir();
    }
}
