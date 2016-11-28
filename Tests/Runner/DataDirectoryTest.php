<?php

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\DockerBundle\Docker\Runner\DataDirectory;
use Keboola\Temp\Temp;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class DataDirectoryTest extends \PHPUnit_Framework_TestCase
{
    public function testDataDir()
    {
        $temp = new Temp();
        $dataDir = new DataDirectory($temp->getTmpFolder());
        $dataDir->createDataDir();
        $fs = new Filesystem();
        $this->assertTrue($fs->exists($dataDir->getDataDir()));
        $this->assertTrue($fs->exists($dataDir->getDataDir() . '/in/files/'));
        $this->assertTrue($fs->exists($dataDir->getDataDir() . '/in/tables/'));
        $this->assertTrue($fs->exists($dataDir->getDataDir() . '/in/user/'));
        $this->assertTrue($fs->exists($dataDir->getDataDir() . '/out/files/'));
        $this->assertTrue($fs->exists($dataDir->getDataDir() . '/out/tables/'));
        $dataDir->dropDataDir();
        $this->assertFalse($fs->exists($dataDir->getDataDir()));
        $this->assertFalse($fs->exists($dataDir->getDataDir() . '/in/files/'));
        $this->assertFalse($fs->exists($dataDir->getDataDir() . '/in/tables/'));
        $this->assertFalse($fs->exists($dataDir->getDataDir() . '/in/user/'));
        $this->assertFalse($fs->exists($dataDir->getDataDir() . '/out/files/'));
        $this->assertFalse($fs->exists($dataDir->getDataDir() . '/out/tables/'));
    }

    public function testMove()
    {
        $temp = new Temp();
        $dataDir = new DataDirectory($temp->getTmpFolder());
        $dataDir->createDataDir();
        $fs = new Filesystem();
        $fs->dumpFile($dataDir->getDataDir() . '/out/files/someFile.txt', 'test');
        $fs->dumpFile($dataDir->getDataDir() . '/out/tables/someTable.csv', 'test2');
        $dataDir->moveOutputToInput();
        $this->assertTrue($fs->exists($dataDir->getDataDir() . '/in/files/someFile.txt'));
        $this->assertTrue($fs->exists($dataDir->getDataDir() . '/in/tables/someTable.csv'));
        $this->assertFalse($fs->exists($dataDir->getDataDir() . '/out/files/someFile.txt'));
        $this->assertFalse($fs->exists($dataDir->getDataDir() . '/out/tables/someTable.csv'));
    }

    public function testDropSubfolder()
    {
        $temp = new Temp();
        $dataDir = new DataDirectory($temp->getTmpFolder());
        $dataDir->createDataDir();
        $command = "sudo docker run --volume={$dataDir->getDataDir()}:/data alpine sh -c 'mkdir -p /data/out/tables/subfolder && echo \"x\" > /data/out/tables/subfolder/x'";
        (new Process($command))->mustRun();
        $dataDir->dropDataDir();
    }
}
