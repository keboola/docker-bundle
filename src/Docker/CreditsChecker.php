<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\StorageApi\Client;

class CreditsChecker
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    private function getBillingService()
    {
        $index = $this->client->indexAction();
        foreach ($index['services'] as $service) {
            
        }
    }

    public function checkCredits()
    {

    }
}