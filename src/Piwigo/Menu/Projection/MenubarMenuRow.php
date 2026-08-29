<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

/**
 * One row of the menubar's "Menu" block -- Tags, Search, Comments, About,
 * Notification, plus whatever {@see \Piwigo\Contribution\MenuItem}s
 * plugins have contributed.
 *
 * `$rel` is the bare link type (`search`, `nofollow`), not the
 * `rel="..."` attribute the array this replaces stored verbatim under a
 * `REL` key for the template to echo through `|noescape`.
 *
 * `$counter` renders as the `(n)` suffix beside the label; null means no
 * suffix, which is distinct from 0.
 */
final readonly class MenubarMenuRow
{
    public function __construct(
        public string $url,
        public string $name,
        public ?string $title = null,
        public ?string $rel = null,
        public ?int $counter = null,
    ) {}
}
