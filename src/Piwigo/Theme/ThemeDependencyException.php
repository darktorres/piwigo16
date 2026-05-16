<?php

declare(strict_types=1);

namespace Piwigo\Theme;

/**
 * Thrown when a theme's parent chain forms a cycle or names a parent
 * that has no validated manifest. ThemeRegistry surfaces this so the
 * admin UX can refuse activation and report the broken link.
 */
final class ThemeDependencyException extends \RuntimeException
{
    public function __construct(
        public readonly string $themeId,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
