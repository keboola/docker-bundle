<?php
/**
 * Created by PhpStorm.
 * User: ondra
 * Date: 16/10/17
 * Time: 16:49
 */

namespace Keboola\DockerBundle\Docker\Container;

use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;

class Process extends \Symfony\Component\Process\Process
{
    /**
     * @var OutputFilterInterface
     */
    private $outputFilter;

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
        if ($this->outputFilter) {
            return $this->outputFilter->filter($value);
        } else {
            return $value;
        }
    }
}
