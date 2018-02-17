<?php

namespace Keboola\DockerBundle\Service;

use Keboola\OAuthV2Api\Credentials;
use Keboola\OAuthV2Api\Exception\RequestException;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\StorageApi\Client;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;
use Keboola\Syrup\Service\StorageApi\StorageApiService;

class AuthorizationService
{
    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    /**
     * @var Client
     */
    private $storageClient;

    /**
     * @var string
     */
    private $legacyOauthUrl;

    /**
     * AuthorizationService constructor.
     * @param ObjectEncryptorFactory $encryptorFactory
     * @param StorageApiService $storageApi
     * @param $legacyOauthUrl
     */
    public function __construct(ObjectEncryptorFactory $encryptorFactory, StorageApiService $storageApi, $legacyOauthUrl) {
        $this->encryptorFactory = $encryptorFactory;
        $this->storageClient = $storageApi->getClient();
        $this->legacyOauthUrl = $legacyOauthUrl;
    }

    /**
     * @return string
     */
    private function getOauthUrlV3()
    {
        $services = $this->storageClient->indexAction()['services'];
        foreach ($services as $service) {
            if ($service['id'] == 'oauth') {
                return $service['url'];
            }
        }
        throw new ApplicationException('The oauth service not found.');
    }

    /**
     * @param array $configData
     * @return Credentials
     */
    private function getClient($configData)
    {
        if (isset($configData['oauth_api']['version']) && ($configData['oauth_api']['version'] == 3)) {
            $client = new Credentials($this->storageClient->getTokenString(), [
                'url' => $this->getOauthUrlV3()
            ]);
        } else {
            $client = new Credentials($this->storageClient->getTokenString(), [
                'url' => $this->legacyOauthUrl
            ]);
        }
        $client->enableReturnArrays(true);
        return $client;
    }

    public function getAuthorization($configData, $componentId, $sandboxed)
    {
        $data = [];
        if (isset($configData['oauth_api']['credentials'])) {
            $data['oauth_api']['credentials'] = $configData['oauth_api']['credentials'];
        }
        $client = $this->getClient($configData);
        if (isset($configData['oauth_api']['id'])) {
            // read authorization from API
            try {
                $credentials = $client->getDetail(
                    $componentId,
                    $configData['oauth_api']['id']
                );
                if ($sandboxed) {
                    $decrypted = $credentials;
                } else {
                    $decrypted = $this->encryptorFactory->getEncryptor()->decrypt($credentials);
                }
                $data['oauth_api']['credentials'] = $decrypted;
            } catch (RequestException $e) {
                if (($e->getCode() >= 400) && ($e->getCode() < 500)) {
                    throw new UserException($e->getMessage(), $e);
                } else {
                    throw new ApplicationException($e->getMessage(), $e);
                }
            }
        }
        return $data;
    }


}
