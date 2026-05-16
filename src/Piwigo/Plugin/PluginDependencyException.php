<?php

declare(strict_types=1);

namespace Piwigo\Plugin;

/**
 * Thrown when a plugin's `require:` constraints cannot be satisfied —
 * Piwigo core too old, a dependency missing, a dependency version
 * mismatch, or a dependency cycle.
 */
final class PluginDependencyException extends \RuntimeException
{
    public function __construct(
        public readonly string $pluginId,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
