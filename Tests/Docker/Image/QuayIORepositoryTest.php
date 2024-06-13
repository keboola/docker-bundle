<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

class QuayIORepositoryTest extends BaseImageTest
{
    public function testDownloadedImage()
    {
        Process::fromShellCommandline(
            'sudo docker rmi -f $(sudo docker images -aq quay.io/keboola/docker-bundle-ci)',
        )->run();

        $process = Process::fromShellCommandline('sudo docker images | grep quay.io/keboola/docker-bundle-ci | wc -l');
        $process->run();
        self::assertEquals(0, trim($process->getOutput()));
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-bundle-ci',
                ],
            ],
        ]);
        $image = ImageFactory::getImage(new NullLogger(), $imageConfig, true);
        $image->prepare([]);

        self::assertEquals('quay.io/keboola/docker-bundle-ci:latest', $image->getFullImageId());
        self::assertEquals('keboola/docker-bundle-ci:latest', $image->getPrintableImageId());

        $process = Process::fromShellCommandline('sudo docker images | grep quay.io/keboola/docker-bundle-ci | wc -l');
        $process->run();
        self::assertEquals(1, trim($process->getOutput()));

        Process::fromShellCommandline('sudo docker rmi quay.io/keboola/docker-bundle-ci')->run();
    }
}
