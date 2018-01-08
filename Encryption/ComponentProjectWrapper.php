<?php
/**
 * Created by Ondrej Hlavacek <ondra@keboola.com>
 * Date: 13/10/15
 */

namespace Keboola\DockerBundle\Encryption;

use Keboola\Syrup\Exception\ApplicationException;

class ComponentProjectWrapper extends ComponentWrapper
{
    /**
     * @var string
     */
    protected $projectId;

    /**
     * @return string
     */
    public function getProjectId()
    {
        return $this->projectId;
    }

    /**
     * @param string $projectId
     * @return $this
     */
    public function setProjectId($projectId)
    {
        $this->projectId = $projectId;

        return $this;
    }

    /**
     * @return string
     */
    protected function getKey()
    {
        if (!$this->getComponentId()) {
            throw new ApplicationException("ComponentId not set");
        }
        if (!$this->getProjectId()) {
            throw new ApplicationException("ProjectId not set");
        }
        $fullKey = $this->getComponentId() . "-" . $this->getProjectId() . "-" . parent::getKey();
        $key = substr(hash('sha256', $fullKey), 0, 16);
        return $key;
    }

    /**
     * @inheritdoc
     */
    public function getPrefix()
    {
        return "KBC::ComponentProjectEncrypted==";
    }
}
