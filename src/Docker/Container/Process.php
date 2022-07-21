<?php

namespace Keboola\DockerBundle\Docker\Container;

use Keboola\DockerBundle\Docker\OutputFilter\NullFilter;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Symfony\Component\Process\Process as SymfonyProcess;

class Process extends SymfonyProcess
{
    private OutputFilterInterface $outputFilter;

    public function __construct($commandline, $cwd = null, array $env = null, $input = null, $timeout = 60)
    {
        parent::__construct($commandline, $cwd, $env, $input, $timeout);
        $this->outputFilter = new NullFilter();
    }

    public function getOutput(): string
    {
        return $this->filter(parent::getOutput());
    }

    public function getErrorOutput(): string
    {
        return $this->filter(parent::getErrorOutput());
    }

    public function run(?callable $callback = null, array $env = array()): int
    {
        $myCallback = function ($type, $buffer) use ($callback) {
            if ($callback) {
                if ($this->filter($buffer) !== '') {
                    $callback($type, $this->filter($buffer));
                }
            }
        };
        return parent::run($myCallback);
    }

    public function setOutputFilter(OutputFilterInterface $outputFilter): void
    {
        $this->outputFilter = $outputFilter;
    }

    private function filter(string $value): string
    {
        return $this->outputFilter->filter($value);
    }
}
