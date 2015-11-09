<?php

namespace Keboola\DockerBundle\Tests\Docker\Mock;

use Keboola\Syrup\Encryption\CryptoWrapperInterface;

/**
 * Class ObjectEncryptor
 * @package Keboola\DockerBundle\Tests
 */
class ObjectEncryptor extends \Keboola\Syrup\Service\ObjectEncryptor
{
    public function __construct()
    {
        $this->container = new KernelContainer();
    }

    public function pushWrapper(CryptoWrapperInterface $wrapper)
    {
        $this->container->set($wrapper);
        parent::pushWrapper($wrapper);
    }
}
