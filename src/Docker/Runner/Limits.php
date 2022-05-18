<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Exception\ApplicationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class Limits
{
    const MAX_CPU_LIMIT = 96;

    const DEFAULT_CPU_LIMIT = 2;

    const MAX_MEMORY_LIMIT = 64000;
    public const DYNAMIC_BACKEND_JOBS_FEATURE = 'dynamic-backend-jobs';

    private array $userFeatures;
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
        array $userFeatures,
        ?string $containerType
    ) {
        $this->logger = $logger;
        $this->instanceLimits = $instanceLimits;
        $this->projectLimits = $projectLimits;
        $this->projectFeatures = $projectFeatures;
        $this->userFeatures = $userFeatures;
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
                var_export($projectLimit, true)
            )
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

    public function getCpuLimit(Image $image)
    {
        if (in_array(self::DYNAMIC_BACKEND_JOBS_FEATURE, $this->projectFeatures)) {
            switch ($this->containerType) {
                case null:
                case 'small':
                    $cpuLimit = 1;
                    break;
                case 'medium':
                    $cpuLimit = 2;
                    break;
                case 'large':
                    $cpuLimit = 4;
                    break;
                case 'xlarge':
                    $cpuLimit = 16;
                    break;
                default:
                    $this->logger->warning(sprintf('Unknown containerType "%s"', $this->containerType));
                    $cpuLimit = 1;
            }
            $this->logger->notice("CPU limit: " . $cpuLimit);
            return $cpuLimit;
        }
        $instance = $this->getInstanceCpuLimit();
        $projectLimit = $this->getProjectCpuLimit();
        $this->logger->notice("CPU limits - instance: " . $instance . " project: " . $projectLimit);
        return min($instance, $projectLimit);
    }

    /**
     * @return Range[]
     */
    private function getCPUValidatorConstraints()
    {
        return [
            new Range(['min' => 1, 'max' => self::MAX_CPU_LIMIT])
        ];
    }

    /**
     * @return Range[]
     */
    private function getMemoryValidatorConstraints()
    {
        return [
            new Range(['min' => 1, 'max' => self::MAX_MEMORY_LIMIT])
        ];
    }

    private function getInstanceCpuLimit()
    {
        if (isset($this->instanceLimits['cpu_count'])) {
            $errors = $this->validator->validate(
                $this->instanceLimits['cpu_count'],
                $this->getCPUValidatorConstraints()
            );
            if ($errors->count() === 0) {
                return $this->instanceLimits['cpu_count'];
            }
            throw new ApplicationException(
                "cpu_count is set incorrectly in parameters.yml: " . $errors[0]->getMessage()
            );
        }
        throw new ApplicationException("cpu_count is not set in parameters.yml");
    }

    private function getNodeTypeMultiplier(?string $containerType): int
    {
        switch ($containerType) {
            case null:
            case 'small':
                return 1;
            case 'medium':
                return 2;
            case 'large':
                return 4;
            case 'xlarge':
                return 16;
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
                $this->getCPUValidatorConstraints()
            );
            if ($errors->count() === 0) {
                return $this->projectLimits['runner.cpuParallelism']['value'];
            }
            throw new ApplicationException(
                "runner.cpuParallelism limit is set incorrectly: " . $errors[0]->getMessage()
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
        if (in_array(self::DYNAMIC_BACKEND_JOBS_FEATURE, $this->projectFeatures)) {
            $multiplier = $this->getNodeTypeMultiplier($this->containerType);
            $componentMemory = UnitConverter::connectionMemoryLimitToBytes($image->getSourceComponent()->getMemory());

            // <hack>
            // For the purpose of dynamic backends, the basic (small) setting for transformations is assumed to be
            // 8GB. Unfortunately in the meantime, the component limit was raised to 16GB on some transformations
            // (but not all). This is a workaround for that - the limit is artificially lowered to 8GB in case of
            // dynamic backends and left alone if dynamic backend is not used.
            if (in_array($image->getSourceComponent()->getId(),
                ['keboola.python-transformation-v2', 'keboola.r-transformation-v2'])) {
                $componentMemory = 8 * (10**9);
            }
            // </hack>

            $memoryReservation = $multiplier * $componentMemory;
            return $this->bytesToDockerMemoryLimit($memoryReservation);
        }
        $componentId = $image->getSourceComponent()->getId();
        $limitName = 'runner.' . $componentId . '.memoryLimitMBs';
        if (isset($this->projectLimits[$limitName]['value'])) {
            $errors = $this->validator->validate(
                $this->projectLimits[$limitName]['value'],
                $this->getMemoryValidatorConstraints()
            );
            if ($errors->count() === 0) {
                // limit is just number of megabytes
                return $this->projectLimits[$limitName]['value'] . 'M';
            }
            throw new ApplicationException(
                sprintf("'%s' limit is set incorrectly: %s", $limitName, $errors[0]->getMessage())
            );
        }
        return null;
    }
}
