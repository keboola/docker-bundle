<?php

namespace Keboola\DockerBundle\Docker\Image;

use Syrup\ComponentBundle\Exception\UserException;

class Collection implements \Iterator, \Countable, \ArrayAccess
{
    /**
     * @var Image[]
     */
    private $images = array();

    private $position = 0;

    public function rewind()
    {
        $this->position = 0;
    }

    public function current()
    {
        return $this->images[$this->position];
    }

    public function key()
    {
        return $this->position;
    }

    public function next()
    {
        ++$this->position;
    }

    public function valid()
    {
        return isset($this->images[$this->position]);
    }

    public function count()
    {
        return count($this->images);
    }

    /**
     *
     * Adds an Image to collection
     *
     * @param Image $image
     */
    public function add(Image $image)
    {
        $found = false;
        foreach ($this->images as $key => $item) {
            if ($item->getId() == $image->getId()) {
                $found = true;
                $this->images[$key] = $item;
            }
        }
        if (!$found) {
            $this->images[] = $image;
        }
    }

    /**
     *
     * Retrieves an item from the array
     *
     * @param $name
     * @return Image
     * @throws UserException
     */
    public function get($name)
    {
        foreach ($this->images as $item) {
            if ($item->getId() == $name) {
                return $item;
            }
        }
        throw new UserException(
            "Image '{$name}' not found."
        );

    }

    /**
     * @param $offset
     * @param $value
     */
    public function offsetSet($offset, $value)
    {
        $this->add($value);
    }

    /**
     * @param $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        foreach ($this->images as $key => $item) {
            if ($item->getId() == $offset) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param $offset
     */
    public function offsetUnset($offset)
    {
        foreach ($this->images as $key => $item) {
            if ($item->getId() == $offset) {
                unset($this->images[$key]);
            }
        }
    }

    /**
     * @param $offset
     * @return Transformation
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }
}
