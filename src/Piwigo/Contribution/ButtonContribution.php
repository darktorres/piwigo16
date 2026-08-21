<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * A single navigational button a plugin contributes to the index or
 * picture page's own actions bar -- the typed replacement for
 * `Template::addIndexButton()`/`addPictureButton()`, which took a raw,
 * pre-rendered HTML string. Renders as the same `pwg-state-default
 * pwg-button` markup core's own buttons already use (`index.latte`'s own
 * "Calendar"/"Search in this set" buttons): an icon, a label used as
 * both the visible text and the `title` tooltip, and a link. Unlike
 * `ActionContribution`, a button always navigates -- it has no
 * expandable panel.
 *
 * `$icon` is the full icon `<span>` class value, rendered as-is by the
 * consuming template -- not just a `pwg-icon-` suffix, since core's own
 * buttons already use two real, different shapes for this
 * (`"pwg-icon pwg-icon-camera-calendar"` vs. the prefix-less
 * `"gallery-icon-search-folder"`); the caller decides which convention
 * applies, not the template.
 *
 * `$order` defaults to 50, matching the real legacy `BUTTONS_RANK_NEUTRAL`
 * convention every real plugin call site already used.
 */
final readonly class ButtonContribution
{
    public function __construct(
        public string $label,
        public string $url,
        public string $icon,
        public ?string $id = null,
        public int $order = 50,
    ) {}
}
