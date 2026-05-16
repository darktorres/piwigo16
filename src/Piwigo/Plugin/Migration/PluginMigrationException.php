<?php

declare(strict_types=1);

namespace Piwigo\Plugin\Migration;

/**
 * Thrown when a plugin migration cannot be located, instantiated, or
 * executed. The plugin id, migration version (FQCN), and underlying
 * cause are preserved so PluginRegistry callers can surface an
 * actionable admin error.
 */
final class PluginMigrationException extends \RuntimeException
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $version,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
