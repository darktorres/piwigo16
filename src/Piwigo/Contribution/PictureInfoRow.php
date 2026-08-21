<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * A single label/value row a plugin contributes to the picture page's
 * `<dl id="standard" class="imageInfoTable">` list (Author/Created on/
 * Dimensions/...) -- the typed replacement for a
 * `set_prefilter('picture', ...)` regex/`str_replace()` patch against
 * that markup, which every real plugin doing this today (`Copyrights`,
 * `download_counter`, `Extended_author`) hand-writes against the raw
 * HTML.
 *
 * `$value` is a plain, always-escaped string -- no `Html` escape hatch.
 * A plugin whose real content genuinely needs richer markup (a map
 * widget, multi-line formatted data) gets a typed answer for that need
 * when it's actually ported, not a raw-HTML passthrough kept around on
 * spec for it.
 *
 * `$id` names the `<div id="...">` wrapper, matching every real
 * plugin's own convention (`Copyrights_name`, `DownloadCounter`) -- a
 * CSS/JS hook, optional since most rows don't need one.
 */
final readonly class PictureInfoRow
{
    public function __construct(
        public string $label,
        public string $value,
        public ?string $id = null,
        public int $order = 50,
    ) {}
}
