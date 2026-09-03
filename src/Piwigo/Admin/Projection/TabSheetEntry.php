<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Latte\Runtime\Html;

/**
 * One entry of {@see \Piwigo\Admin\Tabsheet::$sheets}.
 *
 * `$caption` is Html, not string (P59): `Tabsheet::add()`'s own public
 * `string $caption` parameter still takes a plain string (every real
 * caller is `CoreTabs::addCoreTabs()`, which builds it from a literal
 * `<span class="icon-...">` fragment plus a `Lang::t()` translation --
 * never attacker- or admin-supplied text), wrapped once here at
 * construction.
 */
final readonly class TabSheetEntry
{
    public function __construct(
        public Html $caption,
        public string $url,
    ) {}
}
