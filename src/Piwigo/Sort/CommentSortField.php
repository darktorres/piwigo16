<?php

declare(strict_types=1);

namespace Piwigo\Sort;

/**
 * The typed column vocabulary backing `Controller\CommentsController`'s
 * (`comments.php`) `sort_by` form field -- mirrors
 * {@see UserSortField}'s own "typed vocabulary, not raw text" shape.
 * `CommentsController`'s own `$sort_by` local array (exactly 2 keys:
 * `date`/`image_id`) already allowlist-validates the raw form value
 * before it reaches this enum, via
 * {@see \Piwigo\Controller\Request\CommentsRequest::fromArrays()}'s own
 * `in_array($sort_by_raw, ['date', 'image_id'], true)` check.
 */
enum CommentSortField
{
    case Date;
    case ImageId;

    public static function fromToken(string $token): ?self
    {
        return match ($token) {
            'date' => self::Date,
            'image_id' => self::ImageId,
            default => null,
        };
    }

    /**
     * `CommentRepository::findAllWithConditions()`'s own DQL-backed
     * ORDER BY column -- `com` is that method's own `CommentEntity`
     * alias.
     */
    public function dqlColumn(): string
    {
        return match ($this) {
            self::Date => 'com.date',
            self::ImageId => 'com.imageId',
        };
    }
}
