<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * A button that toggles an expandable panel of links, rather than
 * navigating directly -- the typed replacement for
 * `Template::concat('PLUGIN_INDEX_ACTIONS', ...)`/
 * `concat('PLUGIN_PICTURE_ACTIONS', ...)`, which took raw, pre-rendered
 * HTML. Matches the shape core's own "Related tags"/"Sort order"/"Photo
 * sizes" index-page buttons already use, and the real
 * `language_switch_17.0.0` plugin's own flag-picker (both: a
 * `pwg-button`-styled toggle with no `href`, wired to a `switchBox`
 * panel via `themes/default/js/switchbox.js`'s `window.SwitchBox.push()`
 * -- the consuming template emits that wiring itself, from `$id`, so a
 * plugin author never writes JS for this).
 *
 * `$id` is required (unlike `ButtonContribution::$id`): it names both the
 * toggle link and its panel DOM element, so the panel can't render at
 * all without one -- `{$id}Link`/`{$id}Box`, the same suffix convention
 * every real core switchBox pair already uses
 * (`derivativeSwitchLink`/`derivativeSwitchBox` etc.). `$icon` is the
 * same full class-value shape as `ButtonContribution::$icon` -- see its
 * own docblock.
 *
 * @see PanelLink
 * @see ButtonContribution
 */
final readonly class ActionContribution
{
    /**
     * @param list<PanelLink> $panel
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $icon,
        public array $panel = [],
        public int $order = 50,
    ) {}
}
