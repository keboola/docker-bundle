<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;

class BaseImageTest extends TestCase
{
    /**
     * @var ObjectEncryptorFactory
     */
    private $encryptorFactory;

    public function setUp()
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . AWS_ECR_ACCESS_KEY_ID);
        putenv('AWS_SECRET_ACCESS_KEY=' . AWS_ECR_SECRET_ACCESS_KEY);
        $this->encryptorFactory = new ObjectEncryptorFactory(
            'alias/dummy-key',
            'us-east-1',
            hash('sha256', uniqid()),
            hash('sha256', uniqid())
        );
        $this->temp = new Temp('runner-tests');
        $this->temp->initRunFolder();
    }

    protected function getEncryptor()
    {
        return $this->encryptorFactory->getEncryptor();
    }
}
