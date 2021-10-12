<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Configuration\SharedCodeRow;
use Keboola\DockerBundle\Docker\Runner\SharedCodeContext;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class SharedCodeResolver
{
    const KEBOOLA_SHARED_CODE = 'keboola.shared-code';

    /**
     * @var ClientWrapper
     */
    private $clientWrapper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ClientWrapper $clientWrapper, LoggerInterface $logger)
    {
        $this->clientWrapper = $clientWrapper;
        $this->logger = $logger;
    }

    /**
     * @param JobDefinition[] $jobDefinitions
     * @return JobDefinition[]
     */
    public function resolveSharedCode(array $jobDefinitions)
    {
        /** @var JobDefinition $jobDefinition */
        $newJobDefinitions = [];
        foreach ($jobDefinitions as $jobDefinition) {
            if (!empty($jobDefinition->getConfiguration()['shared_code_id'])) {
                $sharedCodeId = $jobDefinition->getConfiguration()['shared_code_id'];
            }
            if (!empty($jobDefinition->getConfiguration()['shared_code_row_ids'])) {
                $sharedCodeRowIds = $jobDefinition->getConfiguration()['shared_code_row_ids'];
            }
            if (empty($sharedCodeId) || empty($sharedCodeRowIds)) {
                $newJobDefinitions[] = $jobDefinition;
                continue;
            }

            if ($this->clientWrapper->hasBranch()) {
                $components = new Components($this->clientWrapper->getBranchClient());
            } else {
                $components = new Components($this->clientWrapper->getBasicClient());
            }
            $context = new SharedCodeContext();
            try {
                foreach ($sharedCodeRowIds as $sharedCodeRowId) {
                    $sharedCodeConfiguration = $components->getConfigurationRow(self::KEBOOLA_SHARED_CODE, $sharedCodeId, $sharedCodeRowId);
                    $sharedCodeConfiguration = (new SharedCodeRow())->parse(array('config' => $sharedCodeConfiguration['configuration']));
                    $context->pushValue(
                        $sharedCodeRowId,
                        $sharedCodeConfiguration['code_content']
                    );
                }
            } catch (ClientException $e) {
                throw new UserException('Shared code configuration cannot be read: ' . $e->getMessage(), $e);
            } catch (InvalidConfigurationException $e) {
                throw new UserException('Shared code configuration is invalid: ' . $e->getMessage(), $e);
            }
            $this->logger->info(sprintf(
                'Loaded shared code snippets with ids: "%s".',
                implode(', ', $context->getKeys())
            ));

            $newConfiguration = $jobDefinition->getConfiguration();
            $this->replaceSharedCodeInConfiguration($newConfiguration, $context);

            $newJobDefinitions[] = new JobDefinition(
                $newConfiguration,
                $jobDefinition->getComponent(),
                $jobDefinition->getConfigId(),
                $jobDefinition->getConfigVersion(),
                $jobDefinition->getState(),
                $jobDefinition->getRowId(),
                $jobDefinition->isDisabled()
            );
        }
        return $newJobDefinitions;
    }

    /**
     * @param array $configuration
     * @param SharedCodeContext $context
     */
    private function replaceSharedCodeInConfiguration(&$configuration, $context)
    {
        foreach ($configuration as &$value) {
            if (is_array($value)) {
                if ($this->isScalarOrdinalArray($value)) {
                    $value = $this->replaceSharedCodeInArray($value, $context);
                } else {
                    $this->replaceSharedCodeInConfiguration($value, $context);
                }
            } // else it's a scalar, leave as is - shared code is replaced only in arrays
        }
    }

    /**
     * @param $array
     * @return bool
     */
    private function isScalarOrdinalArray($array)
    {
        foreach ($array as $key => $value) {
            if (!is_scalar($value) || !is_int($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array $nodes
     * @param SharedCodeContext $context
     * @return array
     */
    private function replaceSharedCodeInArray($nodes, $context)
    {
        $renderedNodes = [];
        foreach ($nodes as $node) {
            preg_match_all('/{{([ a-zA-Z0-9_-]+)}}/', $node, $matches, PREG_PATTERN_ORDER);
            $matches = $matches[1];
            array_walk($matches, function (&$v) { $v = trim($v);});
            $filteredMatches = array_intersect($context->getKeys(), $matches);
            if (count($filteredMatches) === 0) {
                $renderedNodes[] = $node;
            } else {
                foreach ($filteredMatches as $match) {
                    $match = trim($match);
                    $renderedNodes = array_merge($renderedNodes, $context->$match);
                }
            }
        }
        return $renderedNodes;
    }
}
