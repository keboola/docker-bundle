<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\OAuthV2Api\Credentials;
use Keboola\Syrup\Service\ObjectEncryptor;

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

    public function __construct(Credentials $client, ObjectEncryptor $encryptor, $componentId, $sandboxed)
    {
        $this->oauthClient = $client;
        $this->componentId = $componentId;
        $this->sandboxed = $sandboxed;
        $this->encryptor = $encryptor;
        $this->oauthClient->enableReturnArrays(true);
    }

    public function getAuthorization($configData)
    {
        // read authorization
        $data = [];
        if (isset($configData['oauth_api']['id'])) {
            $credentials = $this->oauthClient->getDetail(
                $this->componentId,
                $configData['oauth_api']['id']
            );
            if ($this->sandboxed) {
                $decrypted = $credentials;
            } else {
                $decrypted = $this->encryptor->decrypt($credentials);
            }
            $data['oauth_api']['credentials'] = $decrypted;
        }
        return $data;
    }
}
