<?php

namespace Keboola\DockerBundle\Tests;

use Keboola\DockerBundle\Docker\Image\Builder\BuilderParameter;
use Keboola\DockerBundle\Exception\BuildException;
use Keboola\DockerBundle\Exception\BuildParameterException;
use PHPUnit\Framework\TestCase;

class ImageBuildParameterTest extends TestCase
{
    public function testParameter()
    {
        $param = new BuilderParameter('foo', 'string', true);
        self::assertEquals('foo', $param->getName());
        self::assertTrue($param->isRequired());
        self::assertNull($param->getValue());

        $param = new BuilderParameter('bar', 'string', false);
        self::assertEquals('bar', $param->getName());
        self::assertFalse($param->isRequired());
        self::assertNull($param->getValue());

        $param = new BuilderParameter('bar', 'string', false, 'anvil');
        self::assertEquals('bar', $param->getName());
        self::assertFalse($param->isRequired());
        self::assertEquals('anvil', $param->getValue());

        $param = new BuilderParameter('bar', 'fooBar', false);
        self::expectException(BuildException::class);
        self::expectExceptionMessage('Invalid type');
        $param->setValue('barBaz');
    }

    public function testParameterValidationSuccess()
    {
        $param = new BuilderParameter('foo', 'string', true);
        $param->setValue('@!#%@^%$UJTNDFDV');
        self::assertEquals('@!#%@^%$UJTNDFDV', $param->getValue());

        $param = new BuilderParameter('foo', 'argument', true);
        $param->setValue('@!#%@^%$UJTNDFDV');
        self::assertEquals(escapeshellarg('@!#%@^%$UJTNDFDV'), $param->getValue());

        $param = new BuilderParameter('foo', 'integer', true);
        $param->setValue('fooBar');
        self::assertEquals(0, $param->getValue());

        $param = new BuilderParameter('foo', 'integer', true);
        $param->setValue('42');
        self::assertEquals(42, $param->getValue());

        $param = new BuilderParameter('foo', 'plain_string', true);
        $param->setValue('fooBar-baz.Bar');
        self::assertEquals('fooBar-baz.Bar', $param->getValue());

        $param = new BuilderParameter('foo', 'enumeration', true, null, ['baz', 'bar']);
        $param->setValue('baz');
        self::assertEquals('baz', $param->getValue());

        $param = new BuilderParameter('foo', 'enumeration', true, 'bar', ['baz', 'bar']);
        self::assertEquals('bar', $param->getValue());
    }

    public function testParameterValidationFail()
    {
        $param = new BuilderParameter('foo', 'plain_string', true);
        self::expectException(BuildParameterException::class);
        self::expectExceptionMessage('Invalid value');
        $param->setValue('@!#%@^%$UJTNDFDV');

        $param = new BuilderParameter('foo', 'plain_string', true);
        self::expectException(BuildParameterException::class);
        self::expectExceptionMessage('Invalid value');
        $param->setValue(['fooBar']);

        $param = new BuilderParameter('foo', 'string', true);
        self::expectException(BuildParameterException::class);
        self::expectExceptionMessage('Invalid value');
        $param->setValue(['foo' => 'bar']);

        $param = new BuilderParameter('foo', 'integer', true);
        self::expectException(BuildParameterException::class);
        self::expectExceptionMessage('Invalid value');
        $param->setValue(['foo' => '0']);

        $param = new BuilderParameter('foo', 'argument', true);
        self::expectException(BuildParameterException::class);
        self::expectExceptionMessage('Invalid value');
        $param->setValue(['foo' => '0']);

        $param = new BuilderParameter('foo', 'enumeration', true, null, ['baz', 'bar']);
        self::expectException(BuildParameterException::class);
        self::expectExceptionMessage('Invalid value');
        $param->setValue('notABaz');
    }
}
