<?php

namespace Keboola\DockerBundle\Tests\Docker\Image;

use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Docker\ImageFactory;
use Keboola\DockerBundle\Tests\BaseImageTest;
use Keboola\Temp\Temp;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;

class QuayIORepositoryTest extends BaseImageTest
{
    public function testDownloadedImage()
    {
        Process::fromShellCommandline('sudo docker rmi -f $(sudo docker images -aq quay.io/keboola/docker-demo-app)')->run();

        $process = Process::fromShellCommandline('sudo docker images | grep quay.io/keboola/docker-demo-app | wc -l');
        $process->run();
        self::assertEquals(0, trim($process->getOutput()));
        $imageConfig = new Component([
            'data' => [
                'definition' => [
                    'type' => 'quayio',
                    'uri' => 'keboola/docker-demo-app',
                ],
            ],
        ]);
        $image = ImageFactory::getImage($this->getEncryptor(), new NullLogger(), $imageConfig, new Temp(), true);
        $image->prepare([]);

        self::assertEquals('quay.io/keboola/docker-demo-app:latest', $image->getFullImageId());
        self::assertEquals('keboola/docker-demo-app:latest', $image->getPrintableImageId());

        $process = Process::fromShellCommandline('sudo docker images | grep quay.io/keboola/docker-demo-app | wc -l');
        $process->run();
        self::assertEquals(1, trim($process->getOutput()));

        Process::fromShellCommandline('sudo docker rmi quay.io/keboola/docker-demo-app')->run();
    }
}
