<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Piwigo\Core\View;
use Piwigo\Image\DerivativeParams;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `comment_list.latte`'s own typed view -- shared by two real callers:
 * {@see \Piwigo\Controller\CommentsController::__invoke()} (only when
 * there is at least one comment to show) and {@see
 * \Piwigo\Picture\PictureCommentRenderer::render()} (the picture page's
 * own single-image comment list). Lives under `Piwigo\Picture\Projection`
 * (L3Presentation, not `Piwigo\Controller\Projection`, L4Integration)
 * because `PictureCommentRenderer` itself constructs and renders it
 * directly -- deptrac's ruleset forbids L3 from depending upward on
 * L4, and `CommentsController` (L4) depending on this (L3) is the
 * always-allowed direction either way. `$commentDerivativeParams` stays
 * nullable: `PictureCommentRenderer`'s own comment rows never carry a
 * `src_image` (already looking at the one photo above, no per-comment
 * illustration needed), so the template's own `isset($commentDerivativeParams)`
 * guard -- and the `{if isset($comment['src_image'])}` gate wrapping
 * every real dereference of it -- is never even reached in that case.
 */
#[Template('comment_list.latte')]
final readonly class CommentListView implements View
{
    /**
     * @param list<array<string, mixed>> $comments
     */
    public function __construct(
        public array $comments,
        public ?DerivativeParams $commentDerivativeParams,
    ) {}
}
