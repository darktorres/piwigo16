<?php

declare(strict_types=1);

namespace Piwigo\Event\Theme;

/**
 * Dispatched when the active gallery theme changes.
 *
 * Listeners receive the previous and new theme id — `$previousThemeId`
 * is `null` on the first activation (no prior theme). Both fields are
 * readonly; listeners observe rather than mutate.
 *
 * Fired by [[\Piwigo\Theme\ThemeRegistry::activate]] after the DB row
 * for the new theme lands and before the plugin's activate() hook
 * runs, so subscribers see the post-switch state.
 */
final readonly class ThemeChanged
{
    public function __construct(
        public string $newThemeId,
        public ?string $previousThemeId = null,
    ) {
    }
}
