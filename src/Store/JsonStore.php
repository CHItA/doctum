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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class JsonStore implements StoreInterface
{
    private const JSON_PRETTY_PRINT = 128;

    /**
     * @return ClassReflection A ClassReflection instance
     *
     * @throws \InvalidArgumentException if the class does not exist in the store
     */
    public function readClass(Project $project, $name): ClassReflection
    {
        if (!file_exists($this->getFilename($project, $name))) {
            throw new \InvalidArgumentException(sprintf('File "%s" for class "%s" does not exist.', $this->getFilename($project, $name), $name));
        }

        return ClassReflection::fromArray($project, json_decode(file_get_contents($this->getFilename($project, $name)), true));
    }

    public function removeClass(Project $project, $name)
    {
        if (!file_exists($this->getFilename($project, $name))) {
            throw new \RuntimeException(sprintf('Unable to remove the "%s" class.', $name));
        }

        unlink($this->getFilename($project, $name));
    }

    public function writeClass(Project $project, ClassReflection $class)
    {
        file_put_contents($this->getFilename($project, $class->getName()), json_encode($class->toArray(), self::JSON_PRETTY_PRINT));
    }

    public function readProject(Project $project)
    {
        $classes = [];
        $files   = Finder::create()->name('c_*.json')->files()->in($this->getStoreDir($project));
        foreach ($files as $file) {
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            $data = json_decode($contents, true);
            if ($data === false || $data === null) {
                continue;
            }
            $classes[] = ClassReflection::fromArray($project, $data);
        }

        return $classes;
    }

    public function flushProject(Project $project)
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->getStoreDir($project));
    }

    protected function getFilename($project, $name)
    {
        $dir = $this->getStoreDir($project);

        return $dir . '/c_' . md5($name) . '.json';
    }

    protected function getStoreDir(Project $project)
    {
        $dir = $project->getCacheDir() . '/store';

        if (!is_dir($dir)) {
            $filesystem = new Filesystem();
            $filesystem->mkdir($dir);
        }

        return $dir;
    }

}
