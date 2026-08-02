<?php

declare(strict_types=1);

namespace Piwigo\Event\Tag;

/**
 * Typed event for the legacy `get_tag_name_like_where` filter. No handler
 * is registered for it anywhere today -- a pure information carrier. Its
 * one real dispatch site starts `$value` from an empty array, expecting a
 * handler to populate it.
 */
final readonly class GetTagNameLikeWhere
{
    /**
     * @param array<mixed> $value
     */
    public function __construct(
        public array $value,
        public string $tagName,
    ) {}
}
