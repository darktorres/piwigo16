<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use RuntimeException;
use Throwable;

/**
 * Thrown when a plugin's `require:` constraints cannot be satisfied --
 * Piwigo core too old, a dependency missing/inactive, a dependency
 * version mismatch, a dependency cycle, or (the reverse direction)
 * deactivating/uninstalling a plugin something else still depends on.
 */
final class PluginDependencyException extends RuntimeException
{
    public function __construct(
        public readonly string $pluginId,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
