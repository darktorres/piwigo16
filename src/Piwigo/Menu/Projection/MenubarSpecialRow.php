<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

/**
 * One entry of the menubar's "Specials" block -- favorites, most visited,
 * best rated, recent photos, recent albums, random, calendar -- built by
 * {@see \Piwigo\Menu\MenubarRenderer::render()}.
 *
 * `$noFollow` is a flag, where the array this replaces carried the
 * literal string `rel="nofollow"` under a `REL` key and the template
 * echoed it through `|noescape`. Same rendered attribute, but the markup
 * now lives in the template and the data says what it means.
 */
final readonly class MenubarSpecialRow
{
    public function __construct(
        public string $url,
        public string $title,
        public string $name,
        public bool $noFollow = false,
    ) {}
}
