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

namespace Doctum\Renderer;

use Doctum\Indexer;
use Doctum\Message;
use Doctum\Project;
use Doctum\Reflection\ClassReflection;
use Doctum\Tree;
use Symfony\Component\Filesystem\Filesystem;

class Renderer
{
    protected $twig;
    protected $templates;
    protected $filesystem;
    protected $themes;
    protected $theme;
    /** @var int */
    protected $steps;
    /** @var int */
    protected $step;
    protected $tree;
    protected $indexer;
    protected $cachedTree;

    public function __construct(\Twig\Environment $twig, ThemeSet $themes, Tree $tree, Indexer $indexer)
    {
        $this->twig       = $twig;
        $this->themes     = $themes;
        $this->tree       = $tree;
        $this->cachedTree = [];
        $this->indexer    = $indexer;
        $this->filesystem = new Filesystem();
    }

    public function isRendered(Project $project)
    {
        return $this->getDiff($project)->isAlreadyRendered();
    }

    public function render(Project $project, $callback = null, $force = false)
    {
        $cacheDir = $project->getCacheDir() . '/twig';
        $this->twig->setCache($cacheDir);

        if ($force) {
            $project->flushDir($cacheDir);
        }

        $diff = $this->getDiff($project);

        if ($diff->isEmpty()) {
            return $diff;
        }

        $this->steps = count($diff->getModifiedClasses()) + count($diff->getModifiedNamespaces()) + count($this->getTheme($project)->getTemplates('global')) + 1;
        $this->step  = 0;

        $this->theme = $this->getTheme($project);
        $dirs        = $this->theme->getTemplateDirs();
        // add parent directory to be able to extends the same template as the current one but in the parent theme
        foreach ($dirs as $dir) {
            $dirs[] = dirname($dir);
        }
        $this->twig->getLoader()->setPaths(array_unique($dirs));

        $this->twig->addGlobal('has_namespaces', $project->hasNamespaces());
        $this->twig->addGlobal('project', $project);

        $this->renderStaticTemplates($project, $callback);
        $this->renderGlobalTemplates($project, $callback);
        $this->renderNamespaceTemplates($diff->getModifiedNamespaces(), $project, $callback);
        $this->renderClassTemplates($diff->getModifiedClasses(), $project, $callback);

        // cleanup
        foreach ($diff->getRemovedClasses() as $class) {
            foreach ($this->theme->getTemplates('class') as $target) {
                $this->filesystem->remove(sprintf($target, str_replace('\\', '/', $class)));
            }
        }

        $diff->save();

        return $diff;
    }

    protected function renderStaticTemplates(Project $project, $callback = null)
    {
        if (null !== $callback) {
            call_user_func($callback, Message::RENDER_PROGRESS, ['Static', 'Rendering files', $this->step, $this->steps]);
        }

        $dirs = $this->theme->getTemplateDirs();
        foreach ($this->theme->getTemplates('static') as $template => $target) {
            foreach (array_reverse($dirs) as $dir) {
                if (file_exists($dir . '/' . $template)) {
                    $this->filesystem->copy($dir . '/' . $template, $project->getBuildDir() . '/' . $target);

                    continue 2;
                }
            }
        }
    }

    protected function renderGlobalTemplates(Project $project, $callback = null)
    {
        $variables = [
            'namespaces' => $project->getNamespaces(),
            'interfaces' => $project->getProjectInterfaces(),
            'classes' => $project->getProjectClasses(),
            'items' => $this->getIndex($project),
            'index' => $this->indexer->getIndex($project),
            'tree' => $this->getTree($project),
        ];

        foreach ($this->theme->getTemplates('global') as $template => $target) {
            if (null !== $callback) {
                call_user_func($callback, Message::RENDER_PROGRESS, ['Global', $target, $this->step, $this->steps]);
            }

            $this->save($project, $target, $template, $variables);
        }
    }

    protected function renderNamespaceTemplates(array $namespaces, Project $project, $callback = null)
    {
        foreach ($namespaces as $namespace) {
            if (null !== $callback) {
                call_user_func($callback, Message::RENDER_PROGRESS, ['Namespace', $namespace, $this->step, $this->steps]);
            }

            $variables = [
                'namespace' => $namespace,
                'subnamespaces' => $project->getNamespaceSubNamespaces($namespace),
                'functions' => $project->getNamespaceFunctions($namespace),
                'classes' => $project->getNamespaceClasses($namespace),
                'interfaces' => $project->getNamespaceInterfaces($namespace),
                'exceptions' => $project->getNamespaceExceptions($namespace),
                'tree' => $this->getTree($project),
            ];

            foreach ($this->theme->getTemplates('namespace') as $template => $target) {
                $this->save($project, sprintf($target, str_replace('\\', '/', $namespace)), $template, $variables);
            }
        }
    }

    /**
     * @param Callable|null $callback
     * @return array<string,mixed>
     */
    protected function getVariablesFromClassReflection(ClassReflection $class, Project $project, $callback = null): array
    {
        if (null !== $callback) {
            call_user_func($callback, Message::RENDER_PROGRESS, ['Class', $class->getName(), $this->step, $this->steps]);
        }

        $properties = $class->getProperties($project->getConfig('include_parent_data'));

        $sortProperties = $project->getConfig('sort_class_properties');
        if ($sortProperties) {
            if (is_callable($sortProperties)) {
                uksort($properties, $sortProperties);
            } else {
                ksort($properties);
            }
        }

        $methods = $class->getMethods($project->getConfig('include_parent_data'));

        $sortMethods = $project->getConfig('sort_class_methods');
        if ($sortMethods) {
            if (is_callable($sortMethods)) {
                uksort($methods, $sortMethods);
            } else {
                ksort($methods);
            }
        }

        $constants = $class->getConstants($project->getConfig('include_parent_data'));

        $sortConstants = $project->getConfig('sort_class_constants');
        if ($sortConstants) {
            if (is_callable($sortConstants)) {
                uksort($constants, $sortConstants);
            } else {
                ksort($constants);
            }
        }

        $traits = $class->getTraits($project->getConfig('include_parent_data'));

        $sortTraits = $project->getConfig('sort_class_traits');
        if ($sortTraits) {
            if (is_callable($sortTraits)) {
                uksort($traits, $sortTraits);
            } else {
                ksort($traits);
            }
        }

        $sortInterfaces = $project->getConfig('sort_class_interfaces');
        if ($sortInterfaces) {
            $class->sortInterfaces($sortInterfaces);
        }

        return [
            'class' => $class,
            'properties' => $properties,
            'methods' => $methods,
            'constants' => $constants,
            'traits' => $traits,
            'tree' => $this->getTree($project),
        ];
    }

    /**
     * @param ClassReflection[] $classes
     * @param Project           $project
     * @param Callable|null     $callback
     * @return void
     */
    protected function renderClassTemplates(array $classes, Project $project, $callback = null)
    {
        foreach ($classes as $class) {
            $variables = $this->getVariablesFromClassReflection($class, $project, $callback);

            foreach ($this->theme->getTemplates('class') as $template => $target) {
                $this->save($project, sprintf($target, str_replace('\\', '/', $class->getName())), $template, $variables);
            }
        }
    }

    protected function save(Project $project, $uri, $template, $variables)
    {
        $depth = substr_count($uri, '/');
        /** @var TwigExtension $twigExtension */
        $twigExtension = $this->twig->getExtension(TwigExtension::class);
        $twigExtension->setCurrentDepth($depth);
        $this->twig->addGlobal('root_path', str_repeat('../', $depth));

        $file = $project->getBuildDir() . '/' . $uri;

        if (!is_dir($dir = dirname($file))) {
            $this->filesystem->mkdir($dir);
        }

        file_put_contents($file, $this->twig->render($template, $variables));
    }

    protected function getIndex(Project $project)
    {
        $items = [];
        foreach ($project->getProjectClasses() as $class) {
            $letter           = strtoupper(substr($class->getShortName(), 0, 1));
            $items[$letter][] = ['class', $class];

            foreach ($class->getProperties() as $property) {
                $letter           = strtoupper(substr($property->getName(), 0, 1));
                $items[$letter][] = ['property', $property];
            }

            foreach ($class->getMethods() as $method) {
                $letter           = strtoupper(substr($method->getName(), 0, 1));
                $items[$letter][] = ['method', $method];
            }
        }
        ksort($items);

        return $items;
    }

    protected function getDiff(Project $project)
    {
        return new Diff($project, $project->getBuildDir() . '/renderer.index');
    }

    protected function getTheme(Project $project)
    {
        return $this->themes->getTheme($project->getConfig('theme'));
    }

    /**
     * Get tree for the given project.
     *
     * @param Project $project
     *
     * @return array
     */
    private function getTree(Project $project)
    {
        $key = $project->getBuildDir();
        if (!isset($this->cachedTree[$key])) {
            $this->cachedTree[$key] = $this->tree->getTree($project);
        }

        return $this->cachedTree[$key];
    }

}
