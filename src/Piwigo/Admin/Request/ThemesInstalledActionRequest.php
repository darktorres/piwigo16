<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_GET['action']`/`$_GET['theme']` for
 * ThemesInstalledPageRenderer::render() (the "installed" tab of page slug
 * "themes") -- P27/SEC-40 Request DTO. No pattern validation needed:
 * `ExtensionLifecycle::performAction()`'s own `switch ($action)` already
 * no-ops on any unrecognized action, and the theme id is only ever used
 * for a repository lookup/array-key access (an unknown id just yields a
 * null row) -- both already safe by construction regardless of this
 * DTO's output.
 */
final readonly class ThemesInstalledActionRequest
{
    private function __construct(
        public ?string $action,
        public ?string $themeId,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        $action = $source['action'] ?? null;
        $theme = $source['theme'] ?? null;

        return new self(
            is_string($action) ? $action : null,
            is_string($theme) ? $theme : null,
        );
    }
}
