<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Latte\Runtime\Html;

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
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int}|null $commentsNavbar
     * @param list<array<string, mixed>>|null $comments
     * @param array<string, mixed>|null $commentAdd
     */
    public function __construct(
        public ?string $commentsOrderUrl,
        public ?string $commentsOrderTitle,
        public ?int $commentCount,
        public ?array $commentsNavbar,
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
