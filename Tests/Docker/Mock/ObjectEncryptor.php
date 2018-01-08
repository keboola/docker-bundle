<?php

namespace Keboola\DockerBundle\Tests\Docker\Mock;

use Keboola\Syrup\Encryption\CryptoWrapperInterface;

/**
 * Class ObjectEncryptor
 * @package Keboola\DockerBundle\Tests
 */
class ObjectEncryptor extends \Keboola\Syrup\Service\ObjectEncryptor
{
    public function pushWrapper(CryptoWrapperInterface $wrapper)
    {
        parent::pushWrapper($wrapper);
    }
}
