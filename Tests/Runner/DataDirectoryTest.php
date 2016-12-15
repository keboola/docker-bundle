<?php

namespace Keboola\DockerBundle\Tests\Functional;

use Keboola\DockerBundle\Docker\Runner\DataDirectory;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Temp\Temp;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

class DataDirectoryTest extends \PHPUnit_Framework_TestCase
{

    public function testDataDirectory()
    {
        $temp = new Temp();
        $logger = new Logger('null');
        $logger->pushHandler(new NullHandler());
        $dataDir = $this->getMockBuilder(DataDirectory::class)
            ->setConstructorArgs([$temp->getTmpFolder(), $logger])
            ->setMethods(['getNormalizeCommand'])
            ->getMock();
        $dataDir->method('getNormalizeCommand')
            ->will($this->onConsecutiveCalls(
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sudo docker run --volume=' . $temp->getTmpFolder() . '/data:/data alpine sh -c \'chown 0 /data -R\''
            ));

        /** @var DataDirectory $dataDir */
        $dataDir->createDataDir();
        $dataDir->dropDataDir();
    }

    public function testDataDirectoryTerminate()
    {
        $temp = new Temp();
        $logger = new Logger('null');
        $logger->pushHandler(new NullHandler());
        $dataDir = $this->getMockBuilder(DataDirectory::class)
            ->setConstructorArgs([$temp->getTmpFolder(), $logger])
            ->setMethods(['getNormalizeCommand'])
            ->getMock();
        $dataDir->method('getNormalizeCommand')
            ->will($this->onConsecutiveCalls(
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sh -c -e \'echo "failed: (125) Error response from daemon: devicemapper: Error running deviceResume dm_task_run failed" && exit 125\'',
                'sudo docker run --volume=' . $temp->getTmpFolder() . '/data:/data alpine sh -c \'chown 0 /data -R\''
            ));

        /** @var DataDirectory $dataDir */
        $dataDir->createDataDir();
        try {
            $dataDir->dropDataDir();
            $this->fail("Too many errors must fail");
        } catch (ApplicationException $e) {
        }
    }

    public function testDataDirectoryTimeout()
    {
        $temp = new Temp();
        $logger = new Logger('null');
        $logger->pushHandler(new NullHandler());
        $dataDir = $this->getMockBuilder(DataDirectory::class)
            ->setConstructorArgs([$temp->getTmpFolder(), $logger])
            ->setMethods(['getNormalizeCommand'])
            ->getMock();
        $dataDir->method('getNormalizeCommand')
            ->will($this->onConsecutiveCalls(
                'sleep 70 && sudo docker run --volume=' . $temp->getTmpFolder() . '/data:/data alpine sh -c \'chown 0 /data -R\'',
                'sudo docker run --volume=' . $temp->getTmpFolder() . '/data:/data alpine sh -c \'chown 0 /data -R\''
            ));

        /** @var DataDirectory $dataDir */
        $dataDir->createDataDir();
        $dataDir->dropDataDir();
    }
}
