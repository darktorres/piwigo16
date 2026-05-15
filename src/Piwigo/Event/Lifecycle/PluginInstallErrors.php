<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Typed event for legacy `plugin_install_errors` (dispatch).
 *
 * Dispatched from: src/Piwigo/Admin/Plugins.php
 */
final readonly class PluginInstallErrors
{
    /**
     * @param array<mixed> $errors
     */
    public function __construct(
        public array $errors,
    ) {
    }

    /**
     * @param array<mixed> $errors
     */
    public function withErrors(array $errors): self
    {
        return new self($errors);
    }
}
