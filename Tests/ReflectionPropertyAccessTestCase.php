<?php

declare(strict_types=1);

namespace Keboola\DockerBundle\Tests;

use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

trait ReflectionPropertyAccessTestCase
{
    /**
     * @param mixed $value
     */
    protected static function setPrivatePropertyValue(object $object, string $property, $value): void
    {
        $reflection = self::findPropertyOnClass(new ReflectionClass($object), $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
        $reflection->setAccessible(false);
    }

    /**
     * @return mixed
     */
    protected static function getPrivatePropertyValue(object $object, string $property)
    {
        $reflection = self::findPropertyOnClass(new ReflectionClass($object), $property);
        $reflection->setAccessible(true);
        $value = $reflection->getValue($object);
        $reflection->setAccessible(false);
        return $value;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private static function findPropertyOnClass(ReflectionClass $class, string $propertyName): ReflectionProperty
    {
        try {
            return $class->getProperty($propertyName);
        } catch (ReflectionException $e) {
            $parent = $class->getParentClass();
            if ($parent) {
                return self::findPropertyOnClass($parent, $propertyName);
            }

            throw $e;
        }
    }
}
