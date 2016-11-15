<?php
/**
 * Created by Ondrej Hlavacek <ondra@keboola.com>
 * Date: 13/10/2015
 */

namespace Keboola\DockerBundle\Tests\Encryption;

use Keboola\DockerBundle\Encryption\JsonWrapper;
use Keboola\Syrup\Encryption\BaseWrapper;

class JsonWrapperTest extends \PHPUnit_Framework_TestCase
{

    public function testEncrypt()
    {
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $jsonWrapper = new JsonWrapper($generalKey);

        $encrypted = $jsonWrapper->encrypt(["key" => "value"]);
        $this->assertEquals(["key" => "value"], $jsonWrapper->decrypt($encrypted));
    }

    public function testSerializationFailure()
    {
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $jsonWrapper = new JsonWrapper($generalKey);
        $this->expectException("Keboola\\DockerBundle\\Exception\\EncryptionException");

        $this->expectExceptionMessageRegExp("/Serialization of encrypted data failed/");
        $jsonWrapper->encrypt("string");
    }

    public function testDeserializationFailure()
    {
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $jsonWrapper = new JsonWrapper($generalKey);
        $baseWrapper = new BaseWrapper($generalKey);

        $encryptedString = $baseWrapper->encrypt("string");

        $this->expectException("Keboola\\DockerBundle\\Exception\\EncryptionException");
        $this->expectExceptionMessageRegExp("/Deserialization of decrypted data failed/");
        $jsonWrapper->decrypt($encryptedString);
    }
}
