<?php

namespace Keboola\DockerBundle\Encryption;

use Keboola\DockerBundle\Exception\EncryptionException;
use Keboola\Syrup\Encryption\BaseWrapper;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

class JsonWrapper extends BaseWrapper
{
    /**
     * @param $encryptedData string
     * @return array decrypted data
     */
    public function decrypt($encryptedData)
    {
        $jsonString = parent::decrypt($encryptedData);
        $decoder = new JsonDecode();
        try {
            $data = $decoder->decode($jsonString, 'json', ['json_decode_associative' => true]);
        } catch (UnexpectedValueException $e) {
            throw new EncryptionException("Deserialization of decrypted data failed: " . $e->getMessage(), $e);
        }
        return $data;
    }

    /**
     * @param array $data
     * @return string
     */
    public function encrypt($data)
    {
        $encoder = new JsonEncode();
        if (!is_array($data)) {
            throw new EncryptionException("Serialization of encrypted data failed: data is not array");
        }
        try {
            $jsonString = $encoder->encode($data, 'json');
        } catch (UnexpectedValueException $e) {
            throw new EncryptionException("Serialization of encrypted data failed: " . $e->getMessage(), $e);
        }
        return parent::encrypt($jsonString);
    }

    /**
     * @inheritdoc
     */
    public function getPrefix()
    {
        return "KBC::JsonEncrypted==";
    }
}
