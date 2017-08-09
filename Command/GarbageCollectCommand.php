<?php

namespace Keboola\DockerBundle\Command;

use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class GarbageCollectCommand extends BaseCommand
{
    public function configure()
    {
        $this
            ->setName('docker:garbage-collect')
            ->setDescription('Garbage collect unused images')
            ->setDefinition(array(
                new InputArgument('timeout', InputArgument::OPTIONAL, 'Execution timeout', 30)
            ));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $timeout = $input->getArgument('timeout');
        $maxAge = 3600;
        $startTime = microtime();
        $commandTimeout = 60;

        $output->writeln('Clearing old containers');
        try {
            $this->clearContainers($output, $commandTimeout, $startTime, $maxAge);
        } catch (\Exception $e) {
            $output->writeln('Clearing old containers failed ' . $e->getMessage());
        }
        try {
            $output->writeln('Clearing builder images');
            $this->clearBuilderImages($output, $commandTimeout, $startTime, $maxAge);
        } catch (\Exception $e) {
            $output->writeln('Clearing builder images failed ' . $e->getMessage());
        }
        try {
            $output->writeln('Clearing old images');
            $this->clearOldImages($output, $commandTimeout, $startTime, $maxAge);
        } catch (\Exception $e) {
            $output->writeln('Clearing old images failed ' . $e->getMessage());
        }
        try {
            $output->writeln('Clearing dangling');
            $this->clearDangling($output, $commandTimeout);
        } catch (\Exception $e) {
            $output->writeln('Clearing dangling failed ' . $e->getMessage());
        }
    }

    private function clearContainers(OutputInterface $output, $commandTimeout, $startTime, $maxAge)
    {
        $containers = new Process('docker ps --all --quiet');
        $containers->setTimeout($commandTimeout);
        $containers->mustRun();
        $containerIds = explode('\n', $containers->getOutput());
        foreach ($containerIds as $containerId) {
            if (empty(trim($containerId))) {
                continue;
            }
            try {
                $containerId = trim($containerId);
                $process = new Process('sudo docker inspect ' . $containerId);
                $process->setTimeout($commandTimeout);
                $process->mustRun();
                $inspect = json_decode($process->getOutput(), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException("Failed to decode inspect " . var_export($inspect, true));
                }
                $inspect = array_pop($inspect);
                if (empty($inspect['State']['FinishedAt'])) {
                    $output->writeln('Container ' . $containerId . ' is not finished?');
                    continue;
                }
                $dateDiff = time() - strtotime($inspect['State']['FinishedAt']);
                $output->writeln(
                    'Container ' . $containerId . ' finished ' . $inspect['State']['FinishedAt'] .
                    ' is ' . ($dateDiff / 3600) . ' hours old'
                );
                if ($dateDiff > $maxAge) {
                    $output->writeln('Removing container ' . $containerId);
                    $rmi = new Process('sudo docker rm ' . $containerId);
                    $rmi->setTimeout($commandTimeout);
                    //$rmi->mustRun();
                }
            } catch (\Exception $e) {
                $output->writeln('Error occurred when processing container ' . $containerId . ': ' . $e->getMessage());
            }
            $output->writeln('Running for ' . ($startTime - microtime()) . ' seconds');
        }
    }

    private function clearBuilderImages(OutputInterface $output, $commandTimeout, $startTime, $maxAge)
    {
        $images = new Process(
            'docker images --all --quiet --filter=\'label=com.keboola.docker.runner.origin=builder\''
        );
        $images->setTimeout($commandTimeout);
        $images->mustRun();
        $imageIds = explode('\n', $images->getOutput());
        foreach ($imageIds as $imageId) {
            if (empty(trim($imageId))) {
                continue;
            }
            try {
                $imageId = trim($imageId);
                $process = new Process('sudo docker inspect ' . $imageId);
                $process->setTimeout($commandTimeout);
                $process->mustRun();
                $inspect = json_decode($process->getOutput(), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException("Failed to decode inspect " . var_export($inspect, true));
                }
                $inspect = array_pop($inspect);
                if (empty($inspect['Created'])) {
                    $output->writeln('Container ' . $imageId . ' is not created?');
                    continue;
                }
                $dateDiff = time() - strtotime($inspect['Created']);
                $output->writeln(
                    'Image ' . $imageId . ' created ' . $inspect['Created'] . ' is ' . ($dateDiff / 3600) . ' hours old'
                );
                if ($dateDiff > $maxAge) {
                    $output->writeln('Removing image ' . $imageId);
                    $rmi = new Process('sudo docker rmi ' . $imageId);
                    $rmi->setTimeout($commandTimeout);
                    //$rmi->mustRun();
                }
            } catch (\Exception $e) {
                $output->writeln('Error occurred when processing image ' . $imageId . ': ' . $e->getMessage());
            }
            $output->writeln('Running for ' . ($startTime - microtime()) . ' seconds');
        }
    }

    private function clearOldImages(OutputInterface $output, $commandTimeout, $startTime, $maxAge)
    {
        TODO
        $images = new Process(
            'docker images --all --quiet --filter=\'label=com.keboola.docker.runner.origin=builder\''
        );
        $images->setTimeout($commandTimeout);
        $images->mustRun();
        $imageIds = explode('\n', $images->getOutput());
        foreach ($imageIds as $imageId) {
            if (empty(trim($imageId))) {
                continue;
            }
            try {
                $imageId = trim($imageId);
                $process = new Process('sudo docker inspect ' . $imageId);
                $process->setTimeout($commandTimeout);
                $process->mustRun();
                $inspect = json_decode($process->getOutput(), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \RuntimeException("Failed to decode inspect " . var_export($inspect, true));
                }
                $inspect = array_pop($inspect);
                if (empty($inspect['Created'])) {
                    $output->writeln('Container ' . $imageId . ' is not created?');
                    continue;
                }
                $dateDiff = time() - strtotime($inspect['Created']);
                $output->writeln(
                    'Image ' . $imageId . ' created ' . $inspect['Created'] . ' is ' . ($dateDiff / 3600) . ' hours old'
                );
                if ($dateDiff > $maxAge) {
                    $output->writeln('Removing image ' . $imageId);
                    $rmi = new Process('sudo docker rmi ' . $imageId);
                    $rmi->setTimeout($commandTimeout);
                    //$rmi->mustRun();
                }
            } catch (\Exception $e) {
                $output->writeln('Error occurred when processing image ' . $imageId . ': ' . $e->getMessage());
            }
            $output->writeln('Running for ' . ($startTime - microtime()) . ' seconds');
        }
    }

    private function clearDangling(OutputInterface $output, $commandTimeout)
    {
        $output->writeln("Removing volumes");
        $rmi = new Process('docker volume rm $(docker volume ls --quiet --filter=\'dangling=true\')');
        $rmi->setTimeout($commandTimeout);
        $rmi->mustRun();
        $output->writeln("Removing images");
        $rmi = new Process('docker rmi $(docker images --quiet --filter=\'dangling=true\')');
        $rmi->setTimeout($commandTimeout);
        $rmi->mustRun();
    }
}
