<?php

declare(strict_types=1);

namespace Piwigo\Comment;

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * One immutable object built once from
 * `$params`, passed unchanged to all 4 `CommentRepository` methods below
 * -- each decides for itself which fields it honors (see their own
 * docblocks).
 *
 * $authorId/$imageId/$minDate/$maxDate are real VOs, not raw scalars --
 * the one real caller (`Controller\Api\Comments\CommentListController`)
 * already has
 * validated ids and `Y-m-d H:i:s`-formatted date strings, so building the VOs there (instead of at every
 * consumption site) is a pure typing win. `Comment\Projection\Comment::
 * $authorId` itself deliberately stays plain `?int` (see that
 * Projection's own docblock -- extracted via DQL's `IDENTITY()`, which
 * never hydrates a VO), so `CommentRepository`'s DQL consumer still
 * unwraps `->value` there; `$imageId`/`$minDate`/`$maxDate` compare
 * directly against already-VO-typed entity columns, no unwrap needed on
 * that path.
 */
final readonly class CommentApiCriteria
{
    public function __construct(
        public ?UserId $authorId = null,
        public ?ImageId $imageId = null,
        public ?SqlDateTime $minDate = null,
        public ?SqlDateTime $maxDate = null,
        public ?string $search = null,
        public string $status = 'all',
    ) {}
}
