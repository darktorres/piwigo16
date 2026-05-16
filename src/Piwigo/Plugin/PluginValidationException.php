<?php

declare(strict_types=1);

namespace Piwigo\Plugin;

/**
 * Thrown when a plugin.json fails schema validation, is missing, or
 * contains malformed JSON. PluginRegistry surfaces this so the admin
 * UX can render an actionable error (which file, which JSON-pointer
 * path inside it).
 */
final class PluginValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $manifestPath,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
