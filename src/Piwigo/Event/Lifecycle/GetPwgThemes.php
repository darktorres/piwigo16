<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Typed event for the legacy `get_pwg_themes` filter. No handler is
 * registered for it anywhere today -- a pure information carrier, not a
 * behavior change. `$themes` stays loosely `array<mixed>` (not the
 * precise `array<int|string, string>` ThemeCatalog::getPwgThemes() itself
 * returns): an untyped plugin handler can hand back a `GetPwgThemes` with
 * non-string values, which ThemeCatalog::getPwgThemes() still defends
 * against per-element -- a precise shape here would make PHPStan treat
 * that defense as dead code, same reasoning as GetAdminPluginMenuLinks/
 * GetBatchManagerPrefilters (batch 4).
 */
final readonly class GetPwgThemes
{
    /**
     * @param array<mixed> $themes
     */
    public function __construct(
        public array $themes,
    ) {}
}
