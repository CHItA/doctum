<?php

declare(strict_types = 1);

/*
 * This file is part of the Doctum utility.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Doctum\Store;

use Doctum\Project;
use Doctum\Reflection\ClassReflection;

/**
 * Stores classes in-memory.
 *
 * Mainly useful for unit tests.
 */
class ArrayStore implements StoreInterface
{
    /**
     * @var array<string,ClassReflection>
     */
    private $classes = [];

    public function setClasses($classes)
    {
        foreach ($classes as $class) {
            $this->classes[$class->getName()] = $class;
        }
    }

    public function readClass(Project $project, $name)
    {
        if (!isset($this->classes[$name])) {
            throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $name));
        }

        return $this->classes[$name];
    }

    public function removeClass(Project $project, $name)
    {
        if (!isset($this->classes[$name])) {
            throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $name));
        }

        unset($this->classes[$name]);
    }

    public function writeClass(Project $project, ClassReflection $class)
    {
        $this->classes[$class->getName()] = $class;
    }

    public function readProject(Project $project)
    {
        return $this->classes;
    }

    public function flushProject(Project $project)
    {
        $this->classes = [];
    }

}
