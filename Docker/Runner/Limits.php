<?php

namespace Keboola\DockerBundle\Docker\Runner;

use Keboola\DockerBundle\Docker\Image;
use Keboola\Syrup\Exception\ApplicationException;
use Psr\Log\LoggerInterface;

class Limits
{
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
    }

    public function getMemoryLimit(Image $image)
    {
        return $image->getSourceComponent()->getMemory();
    }

    public function getMemorySwapLimit(Image $image)
    {
        return $image->getSourceComponent()->getMemory();
    }

    public function getCpuSharesLimit(Image $image)
    {
        return $image->getSourceComponent()->getCpuShares();
    }

    public function getNetworkLimit(Image $image)
    {
        return $image->getSourceComponent()->getNetworkType();
    }

    public function getCpuLimit(Image $image)
    {
        return $this->getInstanceCpuLimit();
    }

    private function getInstanceCpuLimit()
    {
        if (isset($this->instanceLimits['cpu_count']) &&
            filter_var(
                $this->instanceLimits['cpu_count'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 128]]
            )
        ) {
            return $this->instanceLimits['cpu_count'];
        }
        throw new ApplicationException("cpu_count is not set correctly in parameters.yml");
    }
}
