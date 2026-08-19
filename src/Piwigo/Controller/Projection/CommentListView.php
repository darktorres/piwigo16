<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Image\DerivativeParams;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `comment_list.latte`'s own typed view -- rendered by {@see
 * \Piwigo\Controller\CommentsController::__invoke()} in place of its
 * former `assignVarFromTemplate('COMMENT_LIST', 'comment_list.latte')`
 * call, only when there is at least one comment to show.
 */
#[Template('comment_list.latte')]
final readonly class CommentListView implements View
{
    /**
     * @param list<array<string, mixed>> $comments
     */
    public function __construct(
        public array $comments,
        public DerivativeParams $commentDerivativeParams,
    ) {}
}
