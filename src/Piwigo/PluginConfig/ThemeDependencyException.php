<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use RuntimeException;
use Throwable;

/**
 * Thrown when a theme's `require:` constraints cannot be satisfied. See
 * `PluginDependencyException`'s own docblock for the same shape's
 * plugin-side rationale.
 */
final class ThemeDependencyException extends RuntimeException
{
    public function __construct(
        public readonly string $themeId,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
