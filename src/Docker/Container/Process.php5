<?php

namespace Keboola\DockerBundle\Docker\Container;

use Keboola\DockerBundle\Docker\OutputFilter\NullFilter;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;

class Process extends \Symfony\Component\Process\Process
{
    /**
     * @var OutputFilterInterface
     */
    private $outputFilter;

    public function __construct($commandline, $cwd = null, array $env = null, $input = null, $timeout = 60, array $options = array())
    {
        parent::__construct($commandline, $cwd, $env, $input, $timeout, $options);
        $this->outputFilter = new NullFilter();
    }

    /**
     * @return string
     */
    public function getOutput()
    {
        return $this->filter(trim(\Keboola\Utils\sanitizeUtf8(parent::getOutput())));
    }

    /**
     * @return string
     */
    public function getErrorOutput()
    {
        return $this->filter(trim(\Keboola\Utils\sanitizeUtf8(parent::getErrorOutput())));
    }

    /**
     * @param callable|null $callback
     * @return int
     */
    public function run($callback = null)
    {
        $myCallback = function ($type, $buffer) use ($callback) {
            if ($callback) {
                $callback($type, $this->filter(trim(\Keboola\Utils\sanitizeUtf8($buffer))));
            }
        };
        return parent::run($myCallback);
    }

    public function setOutputFilter(OutputFilterInterface $outputFilter)
    {
        $this->outputFilter = $outputFilter;
    }

    private function filter($value)
    {
        return WtfWarningFilter::filter($this->outputFilter->filter($value));
    }
}
