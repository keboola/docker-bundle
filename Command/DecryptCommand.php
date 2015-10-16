<?php
/**
 * Created by PhpStorm.
 * User: Ondrej Hlavacek <ondrej.hlavacek@keboola.com>
 * Date: 15/10/15
 */

namespace Keboola\DockerBundle\Command;

use Keboola\Syrup\Exception\UserException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DecryptCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('docker:decrypt')
            ->setDescription('Decrypt string')
            ->addArgument('string', InputArgument::REQUIRED, 'String to decrypt')
            ->addOption('projectId', 'p', InputOption::VALUE_OPTIONAL, 'Project id for job specific encryption')
            ->addOption('componentId', 'c', InputOption::VALUE_OPTIONAL, 'Component id for job specific encryption')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $stringToDecrypt = $input->getArgument('string');

        if ($input->getOption("projectId") && !$input->getOption("componentId") || !$input->getOption("projectId") && $input->getOption("componentId")) {
            throw new UserException("Both projectId and componentId options have to be set.");
        }

        if ($input->getOption("projectId") && $input->getOption("componentId")) {
            $encryptor = $this->getContainer()->get("syrup.job_object_encryptor");
            $cryptoWrapper = $this->getContainer()->get("syrup.job_crypto_wrapper");
            $cryptoWrapper->setComponentId($input->getOption("componentId"));
            $cryptoWrapper->setProjectId($input->getOption("projectId"));
        } else {
            $encryptor = $this->getContainer()->get("syrup.object_encryptor");
        }

        try {
            $output->writeln($encryptor->decrypt($stringToDecrypt));
        } catch (UserException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new UserException("Decryption failed: " . $e->getMessage());
        }
    }
}
