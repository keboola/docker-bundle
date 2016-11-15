<?php
/**
 * Created by Ondrej Hlavacek <ondra@keboola.com>
 * Date: 13/10/2015
 */

namespace Keboola\DockerBundle\Tests\Encryption;

use Keboola\DockerBundle\Encryption\JsonWrapper;
use Keboola\DockerBundle\Encryption\StackWrapper;

class StackWrapperTest extends \PHPUnit_Framework_TestCase
{
    public function testEncrypt()
    {
        $stackKey = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $stackWrapper = new StackWrapper($generalKey, "my-stack", $stackKey);
        $jsonWrapper = new JsonWrapper($generalKey);

        $encrypted = $stackWrapper->encrypt("mySecretValue");
        $this->assertEquals("mySecretValue", $stackWrapper->decrypt($encrypted));

        $dataDecrypted = $jsonWrapper->decrypt($encrypted);
        $this->arrayHasKey("stacks", $dataDecrypted);
        $this->arrayHasKey("my-stack", $dataDecrypted["stacks"]);
    }

    public function testMissingStacks()
    {
        $stackKey = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $stackWrapper = new StackWrapper($generalKey, "my-stack", $stackKey);
        $jsonWrapper = new JsonWrapper($generalKey);

        $encrypted = $jsonWrapper->encrypt(
            [
                "key" => "value"
            ]
        );

        $this->expectException("Keboola\\DockerBundle\\Exception\\StackDataEncryptionException");
        $this->expectExceptionMessageRegExp("/Stacks not found./");
        $stackWrapper->decrypt($encrypted);
    }

    public function testMissingCurrentStack()
    {
        $stackKey = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $stackWrapper = new StackWrapper($generalKey, "my-stack", $stackKey);
        $jsonWrapper = new JsonWrapper($generalKey);

        $encrypted = $jsonWrapper->encrypt(
            [
                "stacks" => [
                    "unknown-stack" => "unknownvalue"
                ]
            ]
        );

        $this->expectException("Keboola\\DockerBundle\\Exception\\StackDataEncryptionException");
        $this->expectExceptionMessageRegExp("/Stack my-stack not found./");
        $stackWrapper->decrypt($encrypted);
    }

    public function testAdd()
    {
        $stack1Key = substr(hash('sha256', uniqid()), 0, 16);
        $stack2Key = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $stack1Wrapper = new StackWrapper($generalKey, "my-stack-1", $stack1Key);
        $stack2Wrapper = new StackWrapper($generalKey, "my-stack-2", $stack2Key);
        $jsonWrapper = new JsonWrapper($generalKey);

        $encrypted = $stack1Wrapper->encrypt("whatever1");
        $encrypted = $stack2Wrapper->add("whatever2", $encrypted);

        $decrypted = $jsonWrapper->decrypt($encrypted);
        $this->arrayHasKey("stacks", $decrypted);
        $this->arrayHasKey("my-stack-1", $decrypted["stacks"]);
        $this->arrayHasKey("my-stack-2", $decrypted["stacks"]);
        $this->assertEquals("whatever1", $stack1Wrapper->decrypt($encrypted));
        $this->assertEquals("whatever2", $stack2Wrapper->decrypt($encrypted));
    }

    public function testAddMissingStacks()
    {
        $stack2Key = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $stack2Wrapper = new StackWrapper($generalKey, "my-stack-2", $stack2Key);
        $jsonWrapper = new JsonWrapper($generalKey);

        $this->expectException("Keboola\\DockerBundle\\Exception\\StackDataEncryptionException");
        $this->expectExceptionMessageRegExp("/Stacks not found./");
        $encrypted = $jsonWrapper->encrypt(["key" => "value"]);
        $stack2Wrapper->add("whatever2", $encrypted);
    }
}
