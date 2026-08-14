<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use LogicException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Renders a native ReflectionType back to PHP/PHPStan source syntax with
 * FQCN (leading-backslash) class names -- shared by ShimClassGenerator
 * (method signatures) and ContextVariableExtractor (property types).
 */
final class ReflectionTypeRenderer
{
    public static function render(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            $rendered = $type->isBuiltin() ? $name : '\\' . $name;
            if ($type->allowsNull() && $name !== 'null' && $name !== 'mixed') {
                return '?' . $rendered;
            }

            return $rendered;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(
                static fn (ReflectionNamedType|ReflectionIntersectionType $t): string => $t instanceof ReflectionNamedType
                    ? ($t->isBuiltin() ? $t->getName() : '\\' . $t->getName())
                    : self::render($t),
                $type->getTypes(),
            ));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(
                self::render(...),
                $type->getTypes(),
            ));
        }

        throw new LogicException('Unhandled ReflectionType subclass: ' . $type::class);
    }
}
