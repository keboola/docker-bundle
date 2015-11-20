<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\Image\Builder\BuilderParameter;
use Keboola\DockerBundle\Exception\BuildException;
use Keboola\DockerBundle\Exception\BuildParameterException;

class ImageBuildParameterTest extends \PHPUnit_Framework_TestCase
{
    public function testParameter()
    {
        $param = new BuilderParameter('foo', 'string', true);
        $this->assertEquals('foo', $param->getName());
        $this->assertTrue($param->isRequired());
        $this->assertNull($param->getValue());

        $param = new BuilderParameter('bar', 'string', false);
        $this->assertEquals('bar', $param->getName());
        $this->assertFalse($param->isRequired());
        $this->assertNull($param->getValue());

        $param = new BuilderParameter('bar', 'string', false, 'anvil');
        $this->assertEquals('bar', $param->getName());
        $this->assertFalse($param->isRequired());
        $this->assertEquals('anvil', $param->getValue());

        try {
            $param = new BuilderParameter('bar', 'fooBar', false);
            $param->setValue('barBaz');
            $this->fail("Invalid parameter type must raise exception");
        } catch (BuildException $e) {
            $this->assertContains('invalid type', strtolower($e->getMessage()));
        }
    }

    public function testParameterValidationSuccess()
    {
        $param = new BuilderParameter('foo', 'string', true);
        $param->setValue('@!#%@^%$UJTNDFDV');
        $this->assertEquals('@!#%@^%$UJTNDFDV', $param->getValue());

        $param = new BuilderParameter('foo', 'argument', true);
        $param->setValue('@!#%@^%$UJTNDFDV');
        $this->assertEquals(escapeshellarg('@!#%@^%$UJTNDFDV'), $param->getValue());

        $param = new BuilderParameter('foo', 'integer', true);
        $param->setValue('fooBar');
        $this->assertEquals(0, $param->getValue());

        $param = new BuilderParameter('foo', 'integer', true);
        $param->setValue('42');
        $this->assertEquals(42, $param->getValue());

        $param = new BuilderParameter('foo', 'plain_string', true);
        $param->setValue('fooBar-baz.Bar');
        $this->assertEquals('fooBar-baz.Bar', $param->getValue());

        $param = new BuilderParameter('foo', 'enumeration', true, null, ['baz', 'bar']);
        $param->setValue('baz');
        $this->assertEquals('baz', $param->getValue());

        $param = new BuilderParameter('foo', 'enumeration', true, 'bar', ['baz', 'bar']);
        $this->assertEquals('bar', $param->getValue());
    }

    public function testParameterValidationFail()
    {
        $param = new BuilderParameter('foo', 'plain_string', true);
        try {
            $param->setValue('@!#%@^%$UJTNDFDV');
            $this->fail("Invalid value must raise exception");
        } catch (BuildParameterException $e) {
            $this->assertContains('invalid value', strtolower($e->getMessage()));
        }

        $param = new BuilderParameter('foo', 'plain_string', true);
        try {
            $param->setValue(['fooBar']);
            $this->fail("Invalid value must raise exception");
        } catch (BuildParameterException $e) {
            $this->assertContains('invalid value', strtolower($e->getMessage()));
        }

        $param = new BuilderParameter('foo', 'string', true);
        try {
            $param->setValue(['foo' => 'bar']);
            $this->fail("Invalid value must raise exception");
        } catch (BuildParameterException $e) {
            $this->assertContains('invalid value', strtolower($e->getMessage()));
        }

        $param = new BuilderParameter('foo', 'integer', true);
        try {
            $param->setValue(['foo' => '0']);
            $this->fail("Invalid value must raise exception");
        } catch (BuildParameterException $e) {
            $this->assertContains('invalid value', strtolower($e->getMessage()));
        }

        $param = new BuilderParameter('foo', 'argument', true);
        try {
            $param->setValue(['foo' => '0']);
            $this->fail("Invalid value must raise exception");
        } catch (BuildParameterException $e) {
            $this->assertContains('invalid value', strtolower($e->getMessage()));
        }

        $param = new BuilderParameter('foo', 'enumeration', true, null, ['baz', 'bar']);
        try {
            $param->setValue('notABaz');
            $this->fail("Invalid value must raise exception");
        } catch (BuildParameterException $e) {
            $this->assertContains('invalid value', strtolower($e->getMessage()));
        }
    }
}
