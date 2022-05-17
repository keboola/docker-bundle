<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Exception\InvalidUnitFormatException;

class UnitConverter
{
    public static function connectionMemoryLimitToBytes(string $memoryLimit): int
    {
        $memoryLimit = strtolower($memoryLimit);
        if (!preg_match('#^([0-9]+)(m|g)$#', $memoryLimit, $matches)) {
            throw new InvalidUnitFormatException(sprintf('Value "%s" is not understood', $memoryLimit));
        }
        switch ($matches[2]) {
            case 'm':
                $multiplier = 10 ** 6;
                break;
            case 'g':
                $multiplier = 10 ** 9;
                break;
            default:
                throw new InvalidUnitFormatException(sprintf('Unit "%s" is not known', $memoryLimit));
        }
        return intval($matches[1]) * $multiplier;
    }
}
