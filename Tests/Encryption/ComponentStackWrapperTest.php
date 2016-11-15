<?php
/**
 * Created by Ondrej Hlavacek <ondra@keboola.com>
 * Date: 13/10/2015
 */

namespace Keboola\DockerBundle\Tests\Encryption;

use Keboola\DockerBundle\Encryption\ComponentStackWrapper;
use Keboola\DockerBundle\Encryption\JsonWrapper;
use Keboola\DockerBundle\Exception\ComponentDataEncryptionException;

class ComponentStackWrapperTest extends \PHPUnit_Framework_TestCase
{
    public function testEncrypt()
    {
        $stackKey = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $componentStackWrapper = new ComponentStackWrapper($generalKey, "my-stack", $stackKey);
        $componentStackWrapper->setComponent("vendor.my-component");
        $jsonWrapper = new JsonWrapper($generalKey);

        $encrypted = $componentStackWrapper->encrypt("mySecretValue");
        $this->assertEquals("mySecretValue", $componentStackWrapper->decrypt($encrypted));

        $dataDecrypted = $jsonWrapper->decrypt($encrypted);
        $this->arrayHasKey("component", $dataDecrypted);
        $this->assertEquals("vendor.my-component", $dataDecrypted["component"]);
    }

    public function testMissingComponent()
    {
        $stackKey = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $componentStackWrapper = new ComponentStackWrapper($generalKey, "my-stack", $stackKey);
        $componentStackWrapper->setComponent("vendor.my-component");
        $jsonWrapper = new JsonWrapper($generalKey);

        $encrypted = $jsonWrapper->encrypt(
            [
                "key" => "value"
            ]
        );

        $this->expectException("Keboola\\DockerBundle\\Exception\\ComponentDataEncryptionException");
        $this->expectExceptionMessageRegExp("/Component mismatch./");
        $componentStackWrapper->decrypt($encrypted);
    }

    public function testInvalidComponent()
    {
        $stackKey = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $componentStackWrapper = new ComponentStackWrapper($generalKey, "my-stack", $stackKey);
        $componentStackWrapper->setComponent("vendor.my-component");
        $jsonWrapper = new JsonWrapper($generalKey);

        $encrypted = $jsonWrapper->encrypt(
            [
                "component" => "some-other-vendor.my-component"
            ]
        );

        $this->expectException("Keboola\\DockerBundle\\Exception\\ComponentDataEncryptionException");
        $this->expectExceptionMessageRegExp("/Component mismatch./");
        $componentStackWrapper->decrypt($encrypted);
    }

    public function testAdd()
    {
        $stack1Key = substr(hash('sha256', uniqid()), 0, 16);
        $stack2Key = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $componentStack1Wrapper = new ComponentStackWrapper($generalKey, "my-stack-1", $stack1Key);
        $componentStack1Wrapper->setComponent("vendor.my-component");
        $componentStack2Wrapper = new ComponentStackWrapper($generalKey, "my-stack-2", $stack2Key);
        $componentStack2Wrapper->setComponent("vendor.my-component");
        $jsonWrapper = new JsonWrapper($generalKey);

        $encrypted = $componentStack1Wrapper->encrypt("whatever1");
        $encrypted = $componentStack2Wrapper->add("whatever2", $encrypted);

        $decrypted = $jsonWrapper->decrypt($encrypted);
        $this->arrayHasKey("stacks", $decrypted);
        $this->arrayHasKey("my-stack-1", $decrypted["stacks"]);
        $this->arrayHasKey("my-stack-2", $decrypted["stacks"]);
        $this->assertEquals("whatever1", $componentStack1Wrapper->decrypt($encrypted));
        $this->assertEquals("whatever2", $componentStack2Wrapper->decrypt($encrypted));
    }

    public function testAddInvalidComponent()
    {
        $stack2Key = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $stack2Wrapper = new ComponentStackWrapper($generalKey, "my-stack-2", $stack2Key);
        $jsonWrapper = new JsonWrapper($generalKey);

        $this->expectException("Keboola\\DockerBundle\\Exception\\ComponentDataEncryptionException");
        $this->expectExceptionMessageRegExp("/Component mismatch./");
        $encrypted = $jsonWrapper->encrypt(["key" => "value"]);
        $stack2Wrapper->add("whatever2", $encrypted);
    }

    public function testCrossComponentLeak()
    {
        $stack1Key = substr(hash('sha256', uniqid()), 0, 16);
        $stack2Key = substr(hash('sha256', uniqid()), 0, 16);
        $generalKey = substr(hash('sha256', uniqid()), 0, 16);
        $componentStack1Wrapper = new ComponentStackWrapper($generalKey, "my-stack-1", $stack1Key);
        $componentStack1Wrapper->setComponent("vendor.my-component");
        $componentStack2Wrapper = new ComponentStackWrapper($generalKey, "my-stack-2", $stack2Key);
        $componentStack2Wrapper->setComponent("vendor.my-component");
        $jsonWrapper = new JsonWrapper($generalKey);
        $attackerStack1Wrapper = new ComponentStackWrapper($generalKey, "my-stack-1", $stack1Key);
        $attackerStack1Wrapper->setComponent("attacker.my-malicious-app");
        $attackerStack2Wrapper = new ComponentStackWrapper($generalKey, "my-stack-2", $stack2Key);
        $attackerStack2Wrapper->setComponent("attacker.my-malicious-app");

        $encrypted = $componentStack1Wrapper->encrypt("whatever1");
        $encrypted = $componentStack2Wrapper->add("whatever2", $encrypted);

        try {
            $attackerStack1Wrapper->decrypt($encrypted);
            $this->fail("Decrypted with another component");
        } catch (ComponentDataEncryptionException $e) {
            $this->assertEquals("Component mismatch.", $e->getMessage());
        }

        // inject different component
        $decrypted = $jsonWrapper->decrypt($encrypted);
        $decrypted["component"] = "attacker.my-malicious-app";
        $encryptedInjected = $jsonWrapper->encrypt($decrypted);

        // decrypt on the same stack
        $this->assertEquals("whatever1", $attackerStack1Wrapper->decrypt($encryptedInjected));
        // decrypt on a different stack
        $this->assertEquals("whatever2", $attackerStack2Wrapper->decrypt($encryptedInjected));
    }
}
