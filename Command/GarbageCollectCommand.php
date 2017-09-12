<?php

namespace Keboola\DockerBundle\Command;

use Keboola\DockerBundle\Docker\Image\Builder\ImageBuilder;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class GarbageCollectCommand extends BaseCommand
{
    /**
     * Timeout for individual command
     * @var int
     */
    private $commandTimeout;

    /**
     * Timeout for entire garbage collect
     * @var int
     */
    private $timeout;

    /**
     * Execution start timestamp
     * @var int
     */
    private $startTime;

    /**
     * @param $command
     * @return string
     */
    public function exec($command)
    {
        $process = new Process($command);
        $process->setTimeout($this->commandTimeout);
        $process->mustRun();
        return $process->getOutput();
    }

    /**
     * @param OutputInterface $output
     * @return bool
     */
    private function checkTimeout(OutputInterface $output)
    {
        //$output->writeln('Running for ' . (microtime(true) - $this->startTime) . ' seconds');
        if ((microtime(true) - $this->startTime) > $this->timeout) {
            $output->writeln("Timeout reached, terminating");
            return false;
        }
        return true;
    }

    /**
     * @param $date
     * @return bool|\DateTime
     */
    private function processDate($date)
    {
        /* The following date formats can be returned by docker
            "2017-08-11T14:12:30.574769788Z"
            "2017-08-11T14:12:30.57476"
            "0001-01-01T00:00:00Z"
        */
        /* docker on some platforms returns microtime with more than 6 digits
        http://php.net/manual/en/datetime.createfromformat.php#121431 */
        $dateTime = \DateTime::createFromFormat('Y-m-d\TH:i:s', substr($date, 0, 19), new \DateTimeZone('UTC'));
        return $dateTime;
    }

    /**
     * @inheritdoc
     */
    public function configure()
    {
        $this
            ->setName('docker:garbage-collect')
            ->setDescription('Garbage collect unused images')
            ->setDefinition([
                new InputArgument('timeout', InputArgument::OPTIONAL, 'Execution timeout', 600),
                new InputArgument('image-age', InputArgument::OPTIONAL, 'Max image age', 86400),
                new InputArgument('container-age', InputArgument::OPTIONAL, 'Max container age', 259200),
                new InputArgument('command-timeout', InputArgument::OPTIONAL, 'Command timeout', 60),
            ]);
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->commandTimeout = $input->getArgument('command-timeout');
        $this->timeout = $input->getArgument('timeout');
        $this->startTime = microtime(true);
        $imageAge = $input->getArgument('image-age');
        $containerAge = $input->getArgument('container-age');

        $output->writeln('Clearing old containers');
        try {
            $this->clearContainers($output, $containerAge);
        } catch (\Exception $e) {
            $output->writeln('Clearing old containers failed ' . $e->getMessage());
        }
        try {
            $output->writeln('Clearing dangling images');
            $this->clearDanglingImages($output);
        } catch (\Exception $e) {
            $output->writeln('Clearing dangling images failed ' . $e->getMessage());
        }
        try {
            $output->writeln('Clearing builder images');
            $this->clearBuilderImages($output, $imageAge);
        } catch (\Exception $e) {
            $output->writeln('Clearing builder images failed ' . $e->getMessage());
        }
        try {
            $output->writeln('Clearing dangling volumes');
            $this->clearDanglingVolumes($output);
        } catch (\Exception $e) {
            $output->writeln('Clearing dangling volumes failed ' . $e->getMessage());
        }
        try {
            $output->writeln('Clearing dangling images');
            $this->clearDanglingImages($output);
        } catch (\Exception $e) {
            $output->writeln('Clearing dangling images failed ' . $e->getMessage());
        }
        $output->writeln('Finished');
    }

    /**
     * @param OutputInterface $output
     * @param $maxAge
     */
    private function clearContainers(OutputInterface $output, $maxAge)
    {
        $containerIds = explode("\n", $this->exec('sudo docker ps --all --quiet'));
        foreach ($containerIds as $containerId) {
            if (!$this->checkTimeout($output)) {
                break;
            }
            $containerId = trim($containerId);
            if (empty($containerId)) {
                continue;
            }
            try {
                $inspect = json_decode($this->exec('sudo docker inspect ' . $containerId), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException("Failed to decode inspect " . var_export($inspect, true));
                }
                $inspect = array_pop($inspect);
            } catch (\Exception $e) {
                // silently skip the container if we can't inspect
                continue;
            }
            try {
                if (empty($inspect['State']['Status']) ||
                    (($inspect['State']['Status'] != 'exited') && ($inspect['State']['Status'] != 'dead')
                        && ($inspect['State']['Status'] != 'created'))) {
                    $output->writeln('Container ' . $containerId . ' is not finished.');
                    continue;
                }
                if (empty($inspect['State']['FinishedAt'])) {
                    $output->writeln('Container ' . $containerId . ' is not finished?');
                    continue;
                }
                $date = $this->processDate($inspect['State']['FinishedAt']);
                $dateDiff = time() - $date->getTimestamp();
                $output->writeln(
                    'Container ' . $containerId . ' finished ' . $inspect['State']['FinishedAt'] .
                    ' is ' . ($dateDiff / 3600) . ' hours old'
                );
                if ($dateDiff > $maxAge) {
                    $output->writeln('Removing container ' . $containerId);
                    $this->exec('sudo docker rm ' . $containerId);
                }
            } catch (\Exception $e) {
                $output->writeln('Error occurred when processing container ' . $containerId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * @param OutputInterface $output
     * @param $maxAge
     */
    private function clearBuilderImages(OutputInterface $output, $maxAge)
    {
        $imageIds = explode(
            "\n",
            $this->exec('sudo docker images --all --quiet --filter=\'label=' . ImageBuilder::COMMON_LABEL . '\'')
        );
        foreach ($imageIds as $imageId) {
            if (!$this->checkTimeout($output)) {
                break;
            }
            $imageId = trim($imageId);
            if (empty($imageId)) {
                continue;
            }
            try {
                $inspect = json_decode($this->exec('sudo docker inspect ' . $imageId), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException("Failed to decode inspect " . var_export($inspect, true));
                }
                $inspect = array_pop($inspect);
            } catch (\Exception $e) {
                // silently skip the image if we can't inspect
                continue;
            }
            try {
                if (empty($inspect['Created'])) {
                    $output->writeln('Container ' . $imageId . ' is not created?');
                    continue;
                }
                $date = $this->processDate($inspect['Created']);
                $dateDiff = time() - $date->getTimestamp();
                $output->writeln(
                    'Image ' . $imageId . ' created ' . $inspect['Created'] . ' is ' . ($dateDiff / 3600) . ' hours old'
                );
                if ($dateDiff > $maxAge) {
                    $output->writeln('Removing image ' . $imageId);
                    $this->exec('sudo docker rmi --force ' . $imageId);
                }
            } catch (\Exception $e) {
                $output->writeln('Error occurred when processing image ' . $imageId . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * @param OutputInterface $output
     */
    private function clearDanglingVolumes(OutputInterface $output)
    {
        $output->writeln("Removing volumes");
        $this->exec('sudo docker volume rm $(sudo docker volume ls --quiet --filter=\'dangling=true\')');
    }

    /**
     * @param OutputInterface $output
     */
    private function clearDanglingImages(OutputInterface $output)
    {
        $output->writeln("Removing images");
        $this->exec('sudo docker rmi $(sudo docker images --quiet --filter=\'dangling=true\')');
    }
}
