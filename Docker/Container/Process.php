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
        return Process::sanitizeUtf8(parent::getOutput());
    }

    /**
     * @return string
     */
    public function getErrorOutput()
    {
        return Process::sanitizeUtf8(parent::getErrorOutput());
    }

    /**
     * @param callable|null $callback
     * @return int
     */
    public function run($callback = null)
    {
        $myCallback = function ($type, $buffer) use ($callback) {
            if ($callback) {
                $callback($type, Process::sanitizeUtf8($buffer));
            }
        };
        return parent::run($myCallback);
    }

    /**
     * @param $string
     * @return string
     */
    public static function sanitizeUtf8($string)
    {
        return htmlspecialchars_decode(htmlspecialchars(trim($string), ENT_NOQUOTES | ENT_IGNORE, 'UTF-8'), ENT_NOQUOTES);
    }
}
