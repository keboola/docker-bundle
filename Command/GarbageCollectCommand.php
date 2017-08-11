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

    private function exec($command)
    {
        $process = new Process($command);
        $process->setTimeout($this->commandTimeout);
        $process->mustRun();
        return $process->getOutput();
    }

    private function checkTimeout(OutputInterface $output)
    {
        //$output->writeln('Running for ' . (microtime(true) - $this->startTime) . ' seconds');
        if ((microtime(true) - $this->startTime) > $this->timeout) {
            $output->writeln("Timeout reached, terminating");
            return false;
        }
        return true;
    }

    public function configure()
    {
        $this
            ->setName('docker:garbage-collect')
            ->setDescription('Garbage collect unused images')
            ->setDefinition([
                new InputArgument('timeout', InputArgument::OPTIONAL, 'Execution timeout', 120),
                new InputArgument('image-age', InputArgument::OPTIONAL, 'Max image age', 86400),
                new InputArgument('container-age', InputArgument::OPTIONAL, 'Max container age', 259200),
                new InputArgument('command-timeout', InputArgument::OPTIONAL, 'Command timeout', 60),
            ]);
    }

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
            $output->writeln('Clearing builder images');
            $this->clearBuilderImages($output, $imageAge);
        } catch (\Exception $e) {
            $output->writeln('Clearing builder images failed ' . $e->getMessage());
        }
        try {
            $output->writeln('Clearing dangling');
            $this->clearDangling($output);
        } catch (\Exception $e) {
            $output->writeln('Clearing dangling failed ' . $e->getMessage());
        }
        $output->writeln('Finished');
    }

    private function clearContainers(OutputInterface $output, $maxAge)
    {
        $containerIds = explode("\n", $this->exec('sudo docker ps --all --quiet'));
        foreach ($containerIds as $containerId) {
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
                if (empty($inspect['State']['FinishedAt'])) {
                    $output->writeln('Container ' . $containerId . ' is not finished?');
                    continue;
                }
                /* docker on some platforms returns microtime with more than 6 digits
                http://php.net/manual/en/datetime.createfromformat.php#121431 */
                $date = \DateTime::createFromFormat(
                    'Y-m-d\TH:i:s\.u',
                    substr($inspect['State']['FinishedAt'], 0, strpos($inspect['State']['FinishedAt'], '.') + 6)
                );
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
            if (!$this->checkTimeout($output)) {
                break;
            }
        }
    }

    private function clearBuilderImages(OutputInterface $output, $maxAge)
    {
        $imageIds = explode(
            "\n",
            $this->exec('sudo docker images --all --quiet --filter=\'label=' . ImageBuilder::COMMON_LABEL . '\'')
        );
        foreach ($imageIds as $imageId) {
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
                if (empty($inspect['Created'])) {
                    $output->writeln('Container ' . $imageId . ' is not created?');
                    continue;
                }
                /* docker on some platforms returns microtime with more than 6 digits
                http://php.net/manual/en/datetime.createfromformat.php#121431 */
                $date = \DateTime::createFromFormat(
                    'Y-m-d\TH:i:s\.u',
                    substr($inspect['Created'], 0, strpos($inspect['Created'], '.') + 6)
                );

                $dateDiff = time() - $date->getTimestamp();
                $output->writeln(
                    'Image ' . $imageId . ' created ' . $inspect['Created'] . ' is ' . ($dateDiff / 3600) . ' hours old'
                );
                if ($dateDiff > $maxAge) {
                    $output->writeln('Removing image ' . $imageId);
                    $this->exec('sudo docker rmi ' . $imageId);
                }
            } catch (\Exception $e) {
                $output->writeln('Error occurred when processing image ' . $imageId . ': ' . $e->getMessage());
            }
            if (!$this->checkTimeout($output)) {
                break;
            }
        }
    }

    private function clearDangling(OutputInterface $output)
    {
        $output->writeln("Removing volumes");
        $this->exec('sudo docker volume rm $(docker volume ls --quiet --filter=\'dangling=true\')');
        $output->writeln("Removing images");
        $this->exec('sudo docker rmi $(docker images --quiet --filter=\'dangling=true\')');
    }
}
