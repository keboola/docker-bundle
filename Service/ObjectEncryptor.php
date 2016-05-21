<?php
/**
 * User: ondra
 * Date: 21/05/16
 * Time: 11:57
 */

namespace Keboola\DockerBundle\Service;

class ObjectEncryptor extends \Keboola\Syrup\Service\ObjectEncryptor
{
    /**
     *
     * pass unencrypted value
     *
     * @param $value
     * @return mixed
     * @throws \InvalidCiphertextException
     */
    protected function decryptValue($value)
    {
        try {
            return parent::decryptValue($value);
        } catch (\InvalidCiphertextException $e) {
            if ($this->findWrapper($value)) {
                throw $e;
            }
            return $value;
        }
    }
}
