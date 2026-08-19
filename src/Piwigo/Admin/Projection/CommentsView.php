<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `comments.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\CommentsPageRenderer::render()}. No `$formAction` field
 * here -- `F_ACTION` has zero real references in `comments.latte`'s
 * own body (comment moderation is a client-side `/api/v1/comments`
 * flow, not this page's own `<form>` submission).
 */
#[Template('comments.latte')]
final readonly class CommentsView implements View
{
    public function __construct(
        public string $csrfToken,
    ) {}
}
