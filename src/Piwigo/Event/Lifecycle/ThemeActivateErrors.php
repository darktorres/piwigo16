<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Typed event for legacy `theme_activate_errors` (dispatch).
 *
 * Dispatched from: src/Piwigo/Admin/Themes.php
 */
final readonly class ThemeActivateErrors
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
