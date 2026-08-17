<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use RuntimeException;
use Throwable;

/**
 * Thrown when a `plugin.json` fails schema validation, is missing,
 * contains malformed JSON, or its declared `main` class doesn't resolve
 * to a real `ExtensionInterface` implementor. `PluginRegistry` throws
 * this; `Admin\Extensions\ExtensionLifecycle` catches
 * `\RuntimeException` and appends `getMessage()` to its own `$errors`
 * array for the admin UI.
 */
final class PluginValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $manifestPath,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
