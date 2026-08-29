<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * One extra link a plugin adds to a row of the site manager's table,
 * beside core's own "synchronize"/"delete" -- the typed replacement for
 * the raw `U_HREF`/`U_HINT`/`U_CAPTION` array the
 * {@see \Piwigo\Controller\Admin\Event\GetAdminsSiteLinks} event used to
 * carry.
 *
 * Narrower than {@see PanelLink} in one direction and wider in another:
 * no icon (the table renders these as bracketed text, like the core
 * links they sit next to) and a `$hint`, which becomes the anchor's
 * `title`.
 */
final readonly class SiteLink
{
    public function __construct(
        public string $label,
        public string $url,
        public string $hint,
    ) {}
}
