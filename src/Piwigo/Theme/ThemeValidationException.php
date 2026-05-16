<?php

declare(strict_types=1);

namespace Piwigo\Theme;

/**
 * Thrown when a theme.json fails schema validation, is missing, or
 * contains malformed JSON. ThemeRegistry surfaces this so the admin
 * UX can render an actionable error (which file, which JSON-pointer
 * path inside it).
 */
final class ThemeValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $themeId,
        public readonly string $manifestPath,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
