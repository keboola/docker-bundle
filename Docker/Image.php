<?php

namespace Keboola\DockerBundle\Docker;

use Keboola\DockerBundle\Docker\Image\DockerHub;
use Keboola\Gelf\ServerFactory;
use Keboola\Syrup\Exception\ApplicationException;
use Keboola\Syrup\Service\ObjectEncryptor;
use Keboola\Temp\Temp;
use Monolog\Logger;

abstract class Image
{
    /**
     * Image Id
     *
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $imageId;

    /**
     * @var string
     */
    protected $tag = "latest";

    /**
     * @var ObjectEncryptor
     */
    protected $encryptor;

    /**
     * @var array
     */
    protected $configData;

    /**
     * @var bool
     */
    private $isMain;

    /**
     * @var Component
     */
    private $component;

    abstract protected function pullImage();

    /**
     * Constructor (use @see {factory()})
     * @param ObjectEncryptor $encryptor
     * @param Component $component
     */
    public function __construct(ObjectEncryptor $encryptor, Component $component)
    {
        $this->encryptor = $encryptor;
        $this->component = $component;
        $this->imageId = $component->getImageDefinition()["uri"];
        if (!empty($component->getImageDefinition()['tag'])) {
            $this->tag = $component->getImageDefinition()['tag'];
        }
    }

    /**
     * @return ObjectEncryptor
     */
    public function getEncryptor()
    {
        return $this->encryptor;
    }

    /**
     * @return string
     */
    public function getImageId()
    {
        return $this->imageId;
    }

    /**
     * @return string
     */
    public function getTag()
    {
        return $this->tag;
    }

    /**
     * @param bool $isMain
     */
    public function setIsMain($isMain)
    {
        $this->isMain = $isMain;
    }

    public function isMain()
    {
        return $this->isMain;
    }

    /**
     * @param ObjectEncryptor $encryptor Encryptor for image definition.
     * @param Logger $logger Logger instance.
     * @param Component $component Docker image runtime configuration.
     * @param bool $isMain True to mark the image as main image.
     * @return Image|DockerHub
     */
    public static function factory(ObjectEncryptor $encryptor, Logger $logger, Component $component, $isMain)
    {
        switch ($component->getType()) {
            case "dockerhub":
                $instance = new Image\DockerHub($encryptor, $component);
                break;
            case "quayio":
                $instance = new Image\QuayIO($encryptor, $component);
                break;
            case "dockerhub-private":
                $instance = new Image\DockerHub\PrivateRepository($encryptor, $component);
                break;
            case "quayio-private":
                $instance = new Image\QuayIO\PrivateRepository($encryptor, $component);
                break;
            case "aws-ecr":
                $instance = new Image\AWSElasticContainerRegistry($encryptor, $component);
                break;
            case "builder":
                $instance = new Image\Builder\ImageBuilder($encryptor, $component);
                $instance->setLogger($logger);
                $temp = new Temp();
                $instance->setTemp($temp);
                break;
            default:
                throw new ApplicationException("Unknown image type: " . $component->getType());
        }
        $instance->setIsMain($isMain);
        return $instance;
    }

    public function getConfigData()
    {
        return $this->configData;
    }

    /**
     * Returns image id with tag
     *
     * @return string
     */
    public function getFullImageId()
    {
        return $this->getImageId() . ":" . $this->getTag();
    }

    /**
     * Prepare the container image so that it can be run.
     *
     * @param array $configData Configuration (same as the one stored in data config file)
     * @throws \Exception
     */
    public function prepare(array $configData)
    {
        $this->configData = $configData;
        $this->pullImage();
    }

    public function getSourceComponent()
    {
        return $this->component;
    }
}
