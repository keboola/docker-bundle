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

    private array $userFeatures;
    private array $projectFeatures;
    private array $projectLimits;
    private array $instanceLimits;
    private LoggerInterface $logger;
    private ValidatorInterface $validator;
    private ?string $backendSize;

    public function __construct(
        LoggerInterface $logger,
        array $instanceLimits,
        array $projectLimits,
        array $projectFeatures,
        array $userFeatures,
        ?string $backendSize
    ) {
        $this->logger = $logger;
        $this->instanceLimits = $instanceLimits;
        $this->projectLimits = $projectLimits;
        $this->projectFeatures = $projectFeatures;
        $this->userFeatures = $userFeatures;
        $this->validator = Validation::createValidator();
        $this->backendSize = $backendSize;
    }

    public function getMemoryLimit(Image $image)
    {
        $projectLimit = $this->getProjectMemoryLimit($image->getSourceComponent()->getId());
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
        $projectLimit = $this->getProjectMemoryLimit($image->getSourceComponent()->getId());
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

    private function getProjectMemoryLimit($componentId)
    {
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
