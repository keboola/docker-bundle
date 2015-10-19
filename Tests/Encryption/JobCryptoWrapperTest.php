<?php
/**
 * Created by Ondrej Hlavacek <ondra@keboola.com>
 * Date: 13/10/2015
 */

namespace Keboola\DockerBundle\Tests\Encryption;

use Keboola\DockerBundle\Encryption\ComponentCryptoWrapper;
use Keboola\DockerBundle\Encryption\JobCryptoWrapper;
use Keboola\Syrup\Encryption\CryptoWrapper;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class JobCryptoWrapperTest extends WebTestCase
{

    public function testJobCryptoWrapperConstructor()
    {
        $secret = substr(sha1(uniqid()), 0, 16);

        $client = static::createClient();
        $container = $client->getContainer();
        $request = Request::create('/docker/docker-dummy-test/run', 'POST');
        $request->headers->set('X-StorageApi-Token', STORAGE_API_TOKEN);
        $container->set('request', $request);
        $clientService = $container->get('syrup.storage_api');

        $encryptor = new JobCryptoWrapper($secret);
        $tokenInfo = $clientService->getClient()->verifyToken();
        $encryptor->setProjectId($tokenInfo["owner"]["id"]);
        $encryptor->setComponentId('docker-dummy-test');

        $encrypted = $encryptor->encrypt('secret');
        $this->assertEquals('secret', $encryptor->decrypt($encrypted));

        // Verify that the key was successfully generated
        $fullKey = 'docker-dummy-test-' . $tokenInfo["owner"]["id"] . "-" . $secret;
        $cryptoWrapper = new CryptoWrapper(substr(sha1($fullKey), 0, 16));
        $this->assertEquals('secret', $cryptoWrapper->decrypt($encrypted));
    }

    /**
     * @covers \Keboola\DockerBundle\Encryption\ComponentCryptoWrapper::encrypt
     * @covers \Keboola\DockerBundle\Encryption\ComponentCryptoWrapper::decrypt
     */
    public function testEncryptor()
    {
        $client = static::createClient();
        $container = $client->getContainer();

        $request = Request::create('/docker/docker-dummy-test/run', 'POST');
        $request->headers->set('X-StorageApi-Token', STORAGE_API_TOKEN);
        $container->set('request', $request);

        $encryptor = $container->get('syrup.job_crypto_wrapper');
        $clientService = $container->get('syrup.storage_api');
        $tokenInfo = $clientService->getClient()->verifyToken();
        $encryptor->setProjectId($tokenInfo["owner"]["id"]);
        $encryptor->setComponentId('docker-dummy-test');

        $encrypted = $encryptor->encrypt('secret');
        $this->assertEquals('secret', $encryptor->decrypt($encrypted));
    }
}
