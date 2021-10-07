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
            array_walk_recursive($config, function (&$node) use ($context) {
                echo "NODE\n";
                var_dump($node);
                echo "\n";

                $nodeAsString = $this->renderSharedCode(
                    json_encode($node),
                    $context
                );
                echo "NODE STRING\n";
                var_dump($nodeAsString);
                echo "\n";
                $node = json_decode(
                    $nodeAsString,
                    true
                );
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new UserException(
                        'Shared code replacement resulted in invalid configuration, error: ' . json_last_error_msg()
                    );
                }
            });
            var_export($config);
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

    /**
     * @param string $node
     * @param SharedCodeContext $context
     * @return array|mixed|string|string[]|null
     */
    private function renderSharedCode($node, $context) {
        echo "\nNODE\n";
        var_export($node);
        preg_match('/\{\{(.*)\}\}/', $node, $matches);
        echo "\nMATCHES\n";
        var_export($matches);
        foreach ($matches as $match) {
            echo "\ngetting value for " . $match . "\n";
            $node = preg_replace('/(\{\{' . $match . '\}\})/', $context->__get($match), $node);
        }
        return $node;
    }
}
