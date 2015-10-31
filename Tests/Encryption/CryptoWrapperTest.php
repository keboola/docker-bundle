<?php
/**
 * Created by Ondrej Hlavacek <ondra@keboola.com>
 * Date: 13/10/2015
 */

namespace Keboola\DockerBundle\Tests\Encryption;

use Keboola\DockerBundle\Encryption\ComponentProjectWrapper;
use Keboola\DockerBundle\Encryption\ComponentWrapper;
use Keboola\Syrup\Service\ObjectEncryptor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class CryptoWrapperTest extends WebTestCase
{

    public function testComponentProjectWrapperConstructor()
    {
        $secret = substr(hash('sha256', uniqid()), 0, 16);

        $client = static::createClient();
        $container = $client->getContainer();
        $request = Request::create('/docker/docker-dummy-test/run', 'POST');
        $request->headers->set('X-StorageApi-Token', STORAGE_API_TOKEN);
        $container->set('request', $request);
        $clientService = $container->get('syrup.storage_api');

        $wrapper = new ComponentProjectWrapper($secret);
        $tokenInfo = $clientService->getClient()->verifyToken();
        $wrapper->setProjectId($tokenInfo["owner"]["id"]);
        $wrapper->setComponentId('docker-dummy-test');

        $encrypted = $wrapper->encrypt('secret');
        $this->assertNotEquals('secret', $encrypted);
        $this->assertEquals('secret', $wrapper->decrypt($encrypted));
    }

    public function testComponentProjectEncrypt()
    {
        $client = static::createClient();
        $container = $client->getContainer();

        $request = Request::create('/docker/docker-dummy-test/run', 'POST');
        $request->headers->set('X-StorageApi-Token', STORAGE_API_TOKEN);
        $container->set('request', $request);

        /** @var ComponentProjectWrapper $encryptor */
        $encryptor = $container->get('syrup.encryption.component_project_wrapper');
        $clientService = $container->get('syrup.storage_api');
        $tokenInfo = $clientService->getClient()->verifyToken();
        $encryptor->setProjectId($tokenInfo["owner"]["id"]);
        $encryptor->setComponentId('docker-dummy-test');

        $encrypted = $encryptor->encrypt('secret');
        $this->assertEquals('secret', $encryptor->decrypt($encrypted));
    }

    public function testComponentWrapperConstructor()
    {
        $secret = substr(hash('sha256', uniqid()), 0, 16);

        $wrapper = new ComponentWrapper($secret);
        $wrapper->setComponentId('docker-dummy-test');

        $encrypted = $wrapper->encrypt('secret');
        $this->assertNotEquals('secret', $encrypted);
        $this->assertEquals('secret', $wrapper->decrypt($encrypted));
    }

    public function testComponentEncrypt()
    {
        $client = static::createClient();
        $container = $client->getContainer();

        /** @var ComponentWrapper $encryptor */
        $encryptor = $container->get('syrup.encryption.component_wrapper');
        $encryptor->setComponentId('docker-dummy-test');

        $encrypted = $encryptor->encrypt('secret');
        $this->assertEquals('secret', $encryptor->decrypt($encrypted));
    }

    public function testFailedDecryptWrapper()
    {
        $secret = substr(hash('sha256', uniqid()), 0, 16);
        $wrapper = new ComponentWrapper($secret);
        $wrapper->setComponentId('docker-dummy-test');

        $wrapper2 = new ComponentWrapper($secret);
        $wrapper2->setComponentId('docker-another-dummy-test');

        $encrypted = $wrapper->encrypt('secret');
        try {
            $wrapper2->decrypt($encrypted);
            $this->fail("Attempt to decrypt value for different components should fail.");
        } catch (\InvalidCiphertextException $e) {
        }
    }

    public function testFailedDecryptEncryptor()
    {
        $client = static::createClient();
        $container = $client->getContainer();

        $request = Request::create('/docker/docker-dummy-test/run', 'POST');
        $request->headers->set('X-StorageApi-Token', STORAGE_API_TOKEN);
        $container->set('request', $request);
        /** @var ObjectEncryptor $encryptor */
        $encryptor = $container->get('syrup.object_encryptor');

        /** @var ComponentWrapper $wrapper */
        $wrapper = $container->get('syrup.encryption.component_wrapper');
        $wrapper->setComponentId('docker-dummy-test');

        /** @var ComponentProjectWrapper $wrapper2 */
        $wrapper2 = $container->get('syrup.encryption.component_project_wrapper');
        $clientService = $container->get('syrup.storage_api');
        $tokenInfo = $clientService->getClient()->verifyToken();
        $wrapper2->setProjectId($tokenInfo["owner"]["id"]);
        $wrapper2->setComponentId('docker-dummy-test');

        $encrypted = $encryptor->encrypt('secret', 'syrup.encryption.component_wrapper');
        try {
            $wrapper2->decrypt($encrypted);
            $this->fail("Attempt to decrypt value for different components should fail.");
        } catch (\InvalidCiphertextException $e) {
        }

        $encrypted2 = $encryptor->encrypt('secret', 'syrup.encryption.component_project_wrapper');
        try {
            $wrapper2->decrypt($encrypted2);
            $this->fail("Attempt to decrypt value for different components should fail.");
        } catch (\InvalidCiphertextException $e) {
        }

        $this->assertEquals('secret', $encryptor->decrypt($encrypted));
        $this->assertEquals('secret', $encryptor->decrypt($encrypted2));
    }
}
