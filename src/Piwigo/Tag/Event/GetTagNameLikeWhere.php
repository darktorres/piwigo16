<?php

declare(strict_types=1);

namespace Piwigo\Tag\Event;

/**
 * Typed event for the legacy `get_tag_name_like_where` filter. No handler
 * is registered for it anywhere today -- a pure information carrier. Its
 * one real dispatch site starts `$value` from an empty array, expecting a
 * handler to populate it. Mutable on `$value`; `$tagName` stays context. Co-located here from `Piwigo\Event\Tag\GetTagNameLikeWhere` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class GetTagNameLikeWhere
{
    /**
     * @param array<mixed> $value
     */
    public function __construct(
        public array $value,
        public readonly string $tagName,
    ) {}
}
