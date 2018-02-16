<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\OAuthV2Api\Credentials;
use Keboola\OAuthV2Api\Exception\RequestException;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Exception\UserException;

class Authorization
{
    /**
     * @var Credentials
     */
    private $oauthClient;

    /**
     * @var bool
     */
    private $sandboxed;

    /**
     * @var ObjectEncryptor
     */
    private $encryptor;

    /**
     * @var string
     */
    private $componentId;

    /**
     * @var Credentials
     */
    private $oauthClientV3;

    public function __construct(Credentials $client, Credentials $clientV3, ObjectEncryptor $encryptor, $componentId, $sandboxed)
    {
        $this->oauthClient = $client;
        $this->componentId = $componentId;
        $this->sandboxed = $sandboxed;
        $this->encryptor = $encryptor;
        $this->oauthClient->enableReturnArrays(true);
        $this->oauthClientV3 = $clientV3;
        $this->oauthClientV3->enableReturnArrays(true);
    }

    public function getAuthorization($configData)
    {
        $data = [];
        if (isset($configData['oauth_api']['credentials'])) {
            $data['oauth_api']['credentials'] = $configData['oauth_api']['credentials'];
        }
        if (isset($configData['oauth_api']['version']) && ($configData['oauth_api']['version'] == 3)) {
            $client = $this->oauthClientV3;
        } else {
            $client = $this->oauthClient;
        }
        if (isset($configData['oauth_api']['id'])) {
            // read authorization from API
            try {
                $credentials = $client->getDetail(
                    $this->componentId,
                    $configData['oauth_api']['id']
                );
                if ($this->sandboxed) {
                    $decrypted = $credentials;
                } else {
                    $decrypted = $this->encryptor->decrypt($credentials);
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
