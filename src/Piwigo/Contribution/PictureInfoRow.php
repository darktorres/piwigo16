<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

use Latte\Runtime\Html;

/**
 * A single label/value row a plugin contributes to the picture page's
 * `<dl id="standard" class="imageInfoTable">` list (Author/Created on/
 * Dimensions/...) -- the typed replacement for a
 * `set_prefilter('picture', ...)` regex/`str_replace()` patch against
 * that markup, which every real plugin doing this today (`Copyrights`,
 * `download_counter`, `Extended_author`, `piwigo-openstreetmap`,
 * `piwigo-forecast`) hand-writes against the raw HTML.
 *
 * `$value` accepts a raw `Html` fragment, not just plain text -- real
 * plugins need it: `piwigo-openstreetmap` embeds a `<div id="map">`
 * widget, `piwigo-forecast` emits multi-line `<b>`/`<br>`-formatted
 * weather data, `Copyrights`/`Extended_author` link to an external URL.
 * A plain `string` value still escapes normally (the common case: a
 * simple translated label/value pair).
 *
 * `$id` names the `<div id="...">` wrapper, matching every real
 * plugin's own convention (`Copyrights_name`, `DownloadCounter`,
 * `map-info`, `forecast-info`) -- a CSS/JS hook, optional since most
 * rows don't need one.
 */
final readonly class PictureInfoRow
{
    public function __construct(
        public string $label,
        public string|Html $value,
        public ?string $id = null,
        public int $order = 50,
    ) {}
}
