<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

/**
 * {@see \Piwigo\Comment\CommentRepository::findDateRange()}'s own
 * earliest/latest `date` row shape -- `GET /api/v1/comments`'s own
 * "filters" date range. `MIN()`/`MAX()` aggregates around
 * `CommentEntity::$date` (`SqlDateTime`-typed) stay plain scalars under
 * hydration -- aggregates never get the column's custom Doctrine Type
 * applied -- so both fields are plain `?string` here, not `SqlDateTime`.
 */
final readonly class CommentDateRange
{
    public function __construct(
        public ?string $startedAt,
        public ?string $endedAt,
    ) {}
}
