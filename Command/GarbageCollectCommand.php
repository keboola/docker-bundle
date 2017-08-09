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
                new InputArgument('timeout', InputArgument::OPTIONAL, "Execution timeout", 30)
            ));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $timeout = $input->getArgument('timeout');
        $commandTimeout = 60;
        $output->writeln("<success>Success</success>");
        $process = new Process(
            "docker images --all --quiet --filter=\"label=com.keboola.docker.runner.origin=builder\""
        );
        $process->setTimeout($commandTimeout);
        $process->mustRun();
        $imageIds = explode("\n", $process->getOutput());
        foreach ($imageIds as $imageId) {
            $process = new Process("sudo docker inspect $imageId");
            $process->setTimeout($commandTimeout);
            $process->mustRun();
            $inspect = json_decode($process->getOutput(), true);
            $output->writeln("<warning>" . strtotime($inspect['Created']) . '</warning>');
        }
    }
}
