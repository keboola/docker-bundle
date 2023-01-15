<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests\Runner;

use Keboola\StorageApi\Client;
use RuntimeException;

trait BackendAssertsTrait
{
    public static function assertFileBackend(string $expectedProvider, Client $client): void
    {
        $tokenData = $client->verifyToken();
        assert(
            $tokenData['owner']['fileStorageProvider'] === $expectedProvider,
            new RuntimeException(sprintf(
                'Project "%s" is not configured with %s file storage backend',
                $tokenData['owner']['id'],
                mb_strtoupper($expectedProvider)
            ))
        );
    }

    public static function assertDefaultTableBackend(string $expectedBackend, Client $client): void
    {
        $owner = $client->verifyToken()['owner'];
        assert(
            $owner['defaultBackend'] === $expectedBackend
                && $owner[sprintf('has%s', mb_convert_case($expectedBackend, MB_CASE_TITLE))] === true,
            new RuntimeException(sprintf(
                'Project "%s" is not configured with %s table storage backend',
                $owner['id'],
                $expectedBackend
            ))
        );
    }
}
