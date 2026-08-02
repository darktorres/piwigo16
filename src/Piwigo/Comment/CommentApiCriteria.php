<?php

declare(strict_types=1);

namespace Piwigo\Comment;

/**
 * Further SQL-modernization audit, Item 13: replaces the ad hoc
 * `list<SqlCondition> $whereClauses` (plus a string-keyed 'author_id'
 * entry used purely as a removable marker) that `Ws\PwgComments::
 * getList()` used to build once and mutate/reuse across 4 different
 * sub-queries. One immutable object built once from `$params`, passed
 * unchanged to all 4 `CommentRepository` methods below -- each decides
 * for itself which fields it honors (see their own docblocks), replacing
 * the original's `unset($where_clauses['author_id'])` array-key
 * convention with real, readable per-method code.
 *
 * $minDate/$maxDate are already `Y-m-d H:i:s`-formatted by the caller
 * (`date_format($dateTime, ...)` can't emit SQL metacharacters) -- this
 * class carries them as opaque strings, same as the original `SqlCondition`
 * values did.
 */
final readonly class CommentApiCriteria
{
    public function __construct(
        public ?int $authorId = null,
        public ?int $imageId = null,
        public ?string $minDate = null,
        public ?string $maxDate = null,
        public ?string $search = null,
        public string $status = 'all',
    ) {}
}
