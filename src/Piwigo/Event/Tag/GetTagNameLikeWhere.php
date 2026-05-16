<?php

declare(strict_types=1);

namespace Piwigo\Event\Tag;

/**
 * Typed event for legacy `get_tag_name_like_where` (dispatch).
 *
 * New in 2.7
 *
 * Dispatched from: src/Piwigo/Admin/Tag/TagAdminService.php
 */
final readonly class GetTagNameLikeWhere
{
    /**
     * @param array<mixed> $value
     */
    public function __construct(
        public array $value,
        public string $tagName,
    ) {
    }
}
