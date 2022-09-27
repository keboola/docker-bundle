<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\OAuthV2Api\Credentials;
use Keboola\OAuthV2Api\Exception\ClientException;

class Authorization
{
    private Credentials $oauthClientV3;
    private JobScopedEncryptor $encryptor;
    private string $componentId;

    public function __construct(
        Credentials $clientV3,
        JobScopedEncryptor $encryptor,
        string $componentId
    ) {
        $this->componentId = $componentId;
        $this->encryptor = $encryptor;
        $this->oauthClientV3 = $clientV3;
    }

    public function getAuthorization($configData)
    {
        $data = [];
        if (isset($configData['oauth_api']['credentials'])) {
            $data['oauth_api']['credentials'] = $configData['oauth_api']['credentials'];
        } else {
            if (isset($configData['oauth_api']['version']) && ($configData['oauth_api']['version'] == 3)) {
                $client = $this->oauthClientV3;
            } else {
                throw new UserException('OAuth Broker v2 has been deprecated on September 30, 2019. https://status.keboola.com/end-of-life-old-oauth-broker');
            }
            if (isset($configData['oauth_api']['id'])) {
                // read authorization from API
                try {
                    $credentials = $client->getDetail(
                        $this->componentId,
                        $configData['oauth_api']['id']
                    );
                    $decrypted = $this->encryptor->decrypt($credentials);
                    $data['oauth_api']['credentials'] = $decrypted;
                } catch (ClientException $e) {
                    if (($e->getCode() >= 400) && ($e->getCode() < 500)) {
                        throw new UserException($e->getMessage(), $e);
                    } else {
                        throw new ApplicationException($e->getMessage(), $e);
                    }
                }
            }
        }
        return $data;
    }
}
