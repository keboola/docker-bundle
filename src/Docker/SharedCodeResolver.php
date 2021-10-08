<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Configuration\SharedCodeRow;
use Keboola\DockerBundle\Docker\Runner\SharedCodeContext;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApiBranch\ClientWrapper;
use Mustache_Engine;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class SharedCodeResolver
{
    /**
     * @var ClientWrapper
     */
    private $clientWrapper;

    const KEBOOLA_SHARED_CODE = 'keboola.shared-code';

    /**
     * @var Mustache_Engine
     */
    private $moustache;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ClientWrapper $clientWrapper, LoggerInterface $logger)
    {
        $this->clientWrapper = $clientWrapper;
        $this->moustache = new Mustache_Engine([
            'escape' => function ($string) {
                return trim(json_encode($string), '"');
            },
            'strict_callables' => true,
        ]);
        $this->logger = $logger;
    }

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
                    // context value must always be serialized
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

            $config = $jobDefinition->getConfiguration();
            $this->array_walk_recursive($config, $context);
            /*
            array_walk_recursive($config, function (&$node) use ($context) {
                $node = $this->renderSharedCode(
                    $node,
                    $context
                );
            });
            */
            $newConfiguration = $config;

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

    public function array_walk_recursive(&$configuration, $context)
    {
        /*
            //- když je node scalar, uděláme replacement a dostaneme array
            //- když je node scalar, neuděláme replacement a dostaneme scalar
            - když je node array of scalars, uděláme replacement a dostaneme array of scalars
            - když je node array of scalars, neuděláme replacement a dostaneme array of scalars
            - když je node array of non-scalars, recurse
        */
        foreach ($configuration as $node => &$value) {
            if (is_array($value)) {
                if ($this->is_scalar_array($value)) {
                    $value = $this->renderSharedCode($value, $context);
                } else {
                    $this->array_walk_recursive($value, $context);
                }
            } // else it's a scalar, leave as is
        }
    }

    private function is_scalar_array($array)
    {
        foreach ($array as $key => $value) {
            if (!is_scalar($value) || !is_int($key)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $node
     * @param SharedCodeContext $context
     * @return array|mixed|string|string[]|null
     */
    private function renderSharedCode(array $nodes, $context) {
        $ret = [];
        foreach ($nodes as $node) {
            $occurrences = preg_match_all('/{{([ a-zA-Z0-9_-]+)}}/', $node, $matches, PREG_PATTERN_ORDER);
            if ($occurrences === 0) {
                $ret[] = $node;
            } else {
                foreach ($matches[1] as $index => $match) {
                    $match = trim($match);
                    if (isset($context->$match)) {
                        //$node = preg_replace('/' . preg_quote($matches[0][$index], '/') . '/', $context->$match, $node);
                        $ret = array_merge($ret, $context->$match);
                    } else {
                        $ret[] = $matches[0][$index];
                    }
                }
            }
        }
        return $ret;
    }
}
