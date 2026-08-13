<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use RuntimeException;
use Throwable;

/**
 * Thrown when a `theme.json` fails schema validation, is missing,
 * contains malformed JSON, or its declared `main` class doesn't resolve
 * to a real `ExtensionInterface` implementor. See
 * `PluginValidationException`'s own docblock for the same shape's
 * plugin-side rationale.
 */
final class ThemeValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $themeId,
        public readonly string $manifestPath,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
