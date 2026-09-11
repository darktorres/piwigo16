<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Piwigo\Menu\MenubarSpecialKind;

/**
 * One entry of the menubar's "Specials" block -- favorites, most visited,
 * best rated, recent photos, recent albums, random, calendar -- built by
 * {@see \Piwigo\Menu\MenubarRenderer::render()}.
 *
 * `$noFollow` is a flag, where the array this replaces carried the
 * literal string `rel="nofollow"` under a `REL` key and the template
 * echoed it through `|noescape`. Same rendered attribute, but the markup
 * now lives in the template and the data says what it means.
 *
 * `$kind` (P29.6) is the stable, non-translated discriminator -- see
 * `MenubarSpecialKind`'s own docblock for why `$title`/`$name` alone
 * aren't safe for a theme to match against.
 */
final readonly class MenubarSpecialRow
{
    public function __construct(
        public string $url,
        public string $title,
        public string $name,
        // No real reader yet -- lands ahead of its consumer on purpose
        // (P29.6's own core/infra-first sequencing): the modus theme's
        // template override is what will read this, once it exists.
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        public MenubarSpecialKind $kind,
        public bool $noFollow = false,
    ) {}
}
