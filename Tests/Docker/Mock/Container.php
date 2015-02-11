<?php
namespace Keboola\DockerBundle\Tests\Docker\Mock;

use Symfony\Component\Process\Process;

/**
 * Class MockContainer
 * @package Keboola\DockerBundle\Tests
 */
class Container extends \Keboola\DockerBundle\Docker\Container
{
    /**
     * @var callable
     */
    protected $runMethod;

    /**
     * @return Process
     */
    public function run()
    {
        return $this->runMethod();
    }

    /**
     * @param callable $runMethod
     * @return $this
     */
    public function setRunMethod($runMethod)
    {
        $this->runMethod = $runMethod;
        return $this;
    }

    /**
     * @param $method
     * @param $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        if (is_callable(array($this, $method))) {
            return call_user_func_array($this->$method, $args);
        }
    }
}
