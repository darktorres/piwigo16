<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * One `<link>` element a page needs inside `<head>` -- the typed
 * replacement for `{do htmlHead(...)}` (docs/PLAN.md's P42) for real,
 * recurring web-page concepts (alternate feeds, canonical links, preload
 * hints), not scoped narrowly to today's one real caller
 * (`NotificationView`'s RSS-discovery tags). A plain public constructor,
 * not `AssetContribution`'s private-constructor-plus-named-factories
 * shape -- that pattern exists there to discriminate between multiple
 * distinct kinds sharing one class; every `HeadLink` has the same
 * uniform shape, so a second constructor path would be cargo-culting.
 */
final readonly class HeadLink
{
    public function __construct(
        public string $rel,
        public string $href,
        public ?string $type = null,
        public ?string $title = null,
    ) {}
}
