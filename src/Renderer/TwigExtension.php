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

use Michelf\MarkdownExtra;
use Doctum\Reflection\Reflection;
use Doctum\Reflection\ClassReflection;
use Doctum\Reflection\MethodReflection;
use Doctum\Reflection\PropertyReflection;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Twig\TwigFilter;

class TwigExtension extends AbstractExtension
{
    protected $markdown;
    protected $project;
    /** @var int|null */
    protected $currentDepth = null;

    /**
     * Returns a list of filters to add to the existing list.
     *
     * @return TwigFilter[] An array of filters
     */
    public function getFilters()
    {
        return [
            new TwigFilter('desc', [$this, 'parseDesc'], ['needs_context' => true, 'is_safe' => ['html']]),
            new TwigFilter('snippet', [$this, 'getSnippet']),
        ];
    }

    /**
     * Returns a list of functions to add to the existing list.
     *
     * @return TwigFunction[] An array of functions
     */
    public function getFunctions()
    {
        return [
            new TwigFunction('function_path', [$this, 'pathForFunction'], ['needs_context' => true, 'is_safe' => ['all']]),
            new TwigFunction('namespace_path', [$this, 'pathForNamespace'], ['needs_context' => true, 'is_safe' => ['all']]),
            new TwigFunction('class_path', [$this, 'pathForClass'], ['needs_context' => true, 'is_safe' => ['all']]),
            new TwigFunction('method_path', [$this, 'pathForMethod'], ['needs_context' => true, 'is_safe' => ['all']]),
            new TwigFunction('property_path', [$this, 'pathForProperty'], ['needs_context' => true, 'is_safe' => ['all']]),
            new TwigFunction('path', [$this, 'pathForStaticFile'], ['needs_context' => true]),
            new TwigFunction(
                'abbr_class',
                static function ($class, bool $absolute = false) {
                    return self::abbrClass($class, $absolute);
                },
                ['is_safe' => ['all']]
            ),
        ];
    }

    public function setCurrentDepth(int $depth): void
    {
        $this->currentDepth = $depth;
    }

    public function pathForFunction(array $context, string $function): string
    {
        return $this->relativeUri($this->currentDepth) . '#function_' . str_replace('\\', '', $function);
    }

    public function pathForClass(array $context, string $class): string
    {
        return $this->relativeUri($this->currentDepth) . str_replace('\\', '/', $class) . '.html';
    }

    public function pathForNamespace(array $context, string $namespace): string
    {
        return $this->relativeUri($this->currentDepth) . str_replace('\\', '/', $namespace) . '.html';
    }

    public function pathForMethod(array $context, MethodReflection $method)
    {
        return $this->relativeUri($this->currentDepth) . str_replace('\\', '/', $method->getClass()->getName()) . '.html#method_' . $method->getName();
    }

    public function pathForProperty(array $context, PropertyReflection $property)
    {
        return $this->relativeUri($this->currentDepth) . str_replace('\\', '/', $property->getClass()->getName()) . '.html#property_' . $property->getName();
    }

    public function pathForStaticFile(array $context, string $file): string
    {
        return $this->relativeUri($this->currentDepth) . $file;
    }

    /**
     * Generate the abbreviation of a class
     *
     * @param ClassReflection|string $class The class
     */
    public static function abbrClass($class, bool $absolute = false): string
    {
        if ($class instanceof ClassReflection) {
            $short = $class->getShortName();
            $class = $class->getName();

            if ($short === $class && !$absolute) {
                return $class;
            }
        } else {
            $parts = explode('\\', $class);

            if (count($parts) === 1 && !$absolute) {
                return $class;
            }

            $short = array_pop($parts);
        }

        return sprintf('<abbr title="%s">%s</abbr>', htmlentities($class, ENT_QUOTES), htmlspecialchars($short));
    }

    public function parseDesc(array $context, $desc, Reflection $classOrFunctionRefl)
    {
        if (!$desc) {
            return $desc;
        }

        if (null === $this->markdown) {
            $this->markdown = new MarkdownExtra();
        }

        // FIXME: the @see argument is more complex than just a class (Class::Method, local method directly, ...)
        $desc = preg_replace_callback(
            '/@see ([^ ]+)/',
            static function ($match) {
                return 'see ' . $match[1];
            },
            $desc
        );

        return preg_replace(['#^<p>\s*#s', '#\s*</p>\s*$#s'], '', $this->markdown->transform($desc));
    }

    public function getSnippet(string $string)
    {
        if (preg_match('/^(.{50,}?)\s.*/m', $string, $matches)) {
            $string = $matches[1];
        }

        return str_replace(["\n", "\r"], '', strip_tags($string));
    }

    protected function relativeUri(?int $value): string
    {
        if (!$value) {
            return '';
        }

        return rtrim(str_repeat('../', $value), '/') . '/';
    }

}
