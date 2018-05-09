<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class Limits
{
    const MAX_CPU_LIMIT = 96;

    const DEFAULT_CPU_LIMIT = 2;

    /**
     * @var array
     */
    private $userFeatures;

    /**
     * @var array
     */
    private $projectFeatures;

    /**
     * @var array
     */
    private $projectLimits;

    /**
     * @var array
     */
    private $instanceLimits;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ValidatorInterface
     */
    private $validator;

    /**
     * Limits constructor.
     * @param LoggerInterface $logger
     * @param array $instanceLimits
     * @param array $projectLimits
     * @param array $projectFeatures
     * @param array $userFeatures
     */
    public function __construct(
        LoggerInterface $logger,
        array $instanceLimits,
        array $projectLimits,
        array $projectFeatures,
        array $userFeatures
    ) {
        $this->logger = $logger;
        $this->instanceLimits = $instanceLimits;
        $this->projectLimits = $projectLimits;
        $this->projectFeatures = $projectFeatures;
        $this->userFeatures = $userFeatures;
        $this->validator = Validation::createValidator();
    }

    public function getMemoryLimit(Image $image)
    {
        return $image->getSourceComponent()->getMemory();
    }

    public function getMemorySwapLimit(Image $image)
    {
        return $image->getSourceComponent()->getMemory();
    }

    public function getNetworkLimit(Image $image)
    {
        return $image->getSourceComponent()->getNetworkType();
    }

    public function getCpuLimit(Image $image)
    {
        $instance = $this->getInstanceCpuLimit();
        $project = $this->getProjectCpuLimit();
        $this->logger->notice("CPU limits - instance: " . $instance . " project: " . $project);
        return min($instance, $project);
    }

    public function getDeviceIOLimits(Image $image)
    {
        return '50m';
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
}
