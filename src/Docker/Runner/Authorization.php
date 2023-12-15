<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\JobScopedEncryptor;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\OAuthV2Api\Credentials;
use Keboola\OAuthV2Api\Exception\ClientException;

class Authorization
{
    private Credentials $oauthClient;
    private JobScopedEncryptor $encryptor;
    private string $componentId;

    public function __construct(
        Credentials $client,
        JobScopedEncryptor $encryptor,
        string $componentId,
    ) {
        $this->componentId = $componentId;
        $this->encryptor = $encryptor;
        $this->oauthClient = $client;
    }

    public function getAuthorization(array $configData): array
    {
        $data = [];
        if (isset($configData['oauth_api']['credentials'])) {
            $data['oauth_api']['credentials'] = $configData['oauth_api']['credentials'];
        } else {
            if (isset($configData['oauth_api']['id'])) {
                // read authorization from API
                try {
                    $credentials = $this->oauthClient->getDetail(
                        $this->componentId,
                        $configData['oauth_api']['id'],
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
