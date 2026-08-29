<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\Projection\Navbar;

/**
 * {@see \Piwigo\Picture\PictureCommentRenderer::render()}'s own return
 * value -- threaded into `PictureController`'s own View construction.
 * `null` throughout (the zero-value instance) when the picture's own
 * categories are all non-commentable -- the render()'s own early-return
 * path. `$commentsOrderUrl`/`$commentsOrderTitle` are independently
 * null from `$commentCount`/`$commentsNavbar`/`$comments`/`$commentList`:
 * the former two are only ever set when at least one comment already
 * exists, the latter four whenever comments are shown at all (even
 * zero of them).
 */
final readonly class PictureCommentsResult
{
    /**
     * @param list<CommentRow>|null $comments
     * @param array<string, mixed>|null $commentAdd
     */
    public function __construct(
        public ?string $commentsOrderUrl,
        public ?string $commentsOrderTitle,
        public ?int $commentCount,
        public ?Navbar $commentsNavbar,
        public ?array $comments,
        public ?array $commentAdd,
        public ?Html $commentList,
    ) {}

    public static function empty(): self
    {
        return new self(
            commentsOrderUrl: null,
            commentsOrderTitle: null,
            commentCount: null,
            commentsNavbar: null,
            comments: null,
            commentAdd: null,
            commentList: null,
        );
    }
}
