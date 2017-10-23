<?php
/**
 * Created by PhpStorm.
 * User: ondra
 * Date: 16/10/17
 * Time: 16:49
 */

namespace Keboola\DockerBundle\Docker\Container;

class Process extends \Symfony\Component\Process\Process
{
    /**
     * @return string
     */
    public function getOutput()
    {
        return \Keboola\Utils\sanitizeUtf8(parent::getOutput());
    }

    /**
     * @return string
     */
    public function getErrorOutput()
    {
        return \Keboola\Utils\sanitizeUtf8(parent::getErrorOutput());
    }

    /**
     * @param callable|null $callback
     * @return int
     */
    public function run($callback = null)
    {
        $myCallback = function ($type, $buffer) use ($callback) {
            if ($callback) {
                $callback($type, \Keboola\Utils\sanitizeUtf8($buffer));
            }
        };
        return parent::run($myCallback);
    }
}
