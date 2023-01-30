<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\StorageApi\Client;
use Keboola\StorageApi\DevBranches;

trait CreateBranchTrait
{

    public function createBranch(Client $client, $branchName): string
    {
        $branches = new DevBranches($client);
        foreach ($branches->listBranches() as $branch) {
            if ($branch['name'] === $branchName) {
                $branches->deleteBranch($branch['id']);
            }
        }
        return (string) $branches->createBranch($branchName)['id'];
    }
}
