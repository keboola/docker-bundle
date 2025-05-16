<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\OutputFilter;

use Keboola\JobQueue\JobConfiguration\Mapping\SecretsRedactorInterface;

interface OutputFilterInterface extends SecretsRedactorInterface
{
    /**
     * Add a single sensitive value
     * @param string $value
     */
    public function addValue(string $value): void;

    /**
     * Collect sensitive values
     * @param array $data Array of arrays containing sensitive values, values with keys marked with '#' are
     * considered sensitive.
     */
    public function collectValues(array $data): void;
}
