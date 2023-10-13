<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\ApplicationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class Limits
{
    private const MAX_CPU_LIMIT = 96;

    private const DEFAULT_CPU_LIMIT = 2;

    private const MAX_MEMORY_LIMIT = 64000;
    public const PAY_AS_YOU_GO_FEATURE = 'pay-as-you-go';

    private array $projectFeatures;
    private array $projectLimits;
    private array $instanceLimits;
    private LoggerInterface $logger;
    private ValidatorInterface $validator;
    private ?string $containerType;

    public function __construct(
        LoggerInterface $logger,
        array $instanceLimits,
        array $projectLimits,
        array $projectFeatures,
        ?string $containerType,
    ) {
        $this->logger = $logger;
        $this->instanceLimits = $instanceLimits;
        $this->projectLimits = $projectLimits;
        $this->projectFeatures = $projectFeatures;
        $this->validator = Validation::createValidator();
        $this->containerType = $containerType;
    }

    public function getMemoryLimit(Image $image)
    {
        $projectLimit = $this->getProjectMemoryLimit($image);
        $this->logger->notice(
            sprintf(
                "Memory limits - component: '%s' project: %s",
                $image->getSourceComponent()->getMemory(),
                var_export($projectLimit, true),
            ),
        );
        if ($projectLimit) {
            return $projectLimit;
        }
        return $image->getSourceComponent()->getMemory();
    }

    public function getMemorySwapLimit(Image $image)
    {
        $projectLimit = $this->getProjectMemoryLimit($image);
        if ($projectLimit) {
            return $projectLimit;
        }
        return $image->getSourceComponent()->getMemory();
    }

    public function getNetworkLimit(Image $image)
    {
        return $image->getSourceComponent()->getNetworkType();
    }

    public function getCpuLimit()
    {
        if (!in_array(self::PAY_AS_YOU_GO_FEATURE, $this->projectFeatures)) {
            switch ($this->containerType) {
                case 'xsmall':
                    $cpuLimit = 1;
                    break;
                case null:
                case 'small':
                    $cpuLimit = 2;
                    break;
                case 'medium':
                    $cpuLimit = 4;
                    break;
                case 'large':
                    $cpuLimit = 14;
                    break;
                default:
                    $this->logger->warning(sprintf('Unknown containerType "%s"', $this->containerType));
                    $cpuLimit = 1;
            }
            $this->logger->notice('CPU limit: ' . $cpuLimit);
            return $cpuLimit;
        }
        $instance = $this->getInstanceCpuLimit();
        $projectLimit = $this->getProjectCpuLimit();
        $this->logger->notice('CPU limits - instance: ' . $instance . ' project: ' . $projectLimit);
        return min($instance, $projectLimit);
    }

    /**
     * @return Range[]
     */
    private function getCPUValidatorConstraints()
    {
        return [
            new Range(['min' => 1, 'max' => self::MAX_CPU_LIMIT]),
        ];
    }

    /**
     * @return Range[]
     */
    private function getMemoryValidatorConstraints()
    {
        return [
            new Range(['min' => 1, 'max' => self::MAX_MEMORY_LIMIT]),
        ];
    }

    private function getInstanceCpuLimit()
    {
        if (isset($this->instanceLimits['cpu_count'])) {
            $errors = $this->validator->validate(
                $this->instanceLimits['cpu_count'],
                $this->getCPUValidatorConstraints(),
            );
            if ($errors->count() === 0) {
                return $this->instanceLimits['cpu_count'];
            }
            throw new ApplicationException(
                'cpu_count is set incorrectly in parameters.yml: ' . $errors[0]->getMessage(),
            );
        }
        throw new ApplicationException('cpu_count is not set in parameters.yml');
    }

    private function getNodeTypeMultiplier(?string $containerType): float
    {
        switch ($containerType) {
            case 'xsmall':
                return 0.5;
            case null:
            case 'small':
                return 1;
            case 'medium':
                return 2;
            case 'large':
                // see https://github.com/keboola/job-queue-daemon/blob/7af7d3853cb81f585e9c4d29a5638ff2ad40107a/src/Cluster/ResourceTransformer.php#L34
                // https://keboola.atlassian.net/wiki/spaces/KB/pages/2234941476/Dynamic+backend+size+per+job+Python+R#Option-2-(preferred-by-odin):
                return 7.1;
            default:
                $this->logger->warning(sprintf('Unknown containerType "%s"', $containerType));
                return 1;
        }
    }

    private function getProjectCpuLimit()
    {
        if (isset($this->projectLimits['runner.cpuParallelism']['value'])) {
            $errors = $this->validator->validate(
                $this->projectLimits['runner.cpuParallelism']['value'],
                $this->getCPUValidatorConstraints(),
            );
            if ($errors->count() === 0) {
                return $this->projectLimits['runner.cpuParallelism']['value'];
            }
            throw new ApplicationException(
                'runner.cpuParallelism limit is set incorrectly: ' . $errors[0]->getMessage(),
            );
        }
        return self::DEFAULT_CPU_LIMIT;
    }

    private function bytesToDockerMemoryLimit(int $memoryLimit): string
    {
        return intval($memoryLimit / (10**6)) . 'M';
    }

    private function getProjectMemoryLimit(Image $image)
    {
        if (!in_array(self::PAY_AS_YOU_GO_FEATURE, $this->projectFeatures)) {
            $multiplier = $this->getNodeTypeMultiplier($this->containerType);
            $componentMemory = UnitConverter::connectionMemoryLimitToBytes($image->getSourceComponent()->getMemory());

            $memoryLimit = (int) round($multiplier * $componentMemory);

            return $this->bytesToDockerMemoryLimit($memoryLimit);
        }
        $componentId = $image->getSourceComponent()->getId();
        $limitName = 'runner.' . $componentId . '.memoryLimitMBs';
        if (isset($this->projectLimits[$limitName]['value'])) {
            $errors = $this->validator->validate(
                $this->projectLimits[$limitName]['value'],
                $this->getMemoryValidatorConstraints(),
            );
            if ($errors->count() === 0) {
                // limit is just number of megabytes
                return $this->projectLimits[$limitName]['value'] . 'M';
            }
            throw new ApplicationException(
                sprintf("'%s' limit is set incorrectly: %s", $limitName, $errors[0]->getMessage()),
            );
        }
        return null;
    }
}
