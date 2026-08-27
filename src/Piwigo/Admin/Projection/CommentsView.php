<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
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
final readonly class CommentsView implements View, HasPageAssets, ExposesPageData
{
    public function __construct(
        public string $csrfToken,
    ) {}

    /**
     * `comments.latte`'s own unconditional `{do combineScript(...)}`x3/
     * `{do combineCss(...)}`x2 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('comments', 'themes/admin/default/js/comments.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/comments.css', id: 'comments'),
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'csrf_token' => $this->csrfToken,
        ];
    }

    /**
     * `comments.latte`'s own unconditional `{do exposeString(...)}`x6
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Yes, delete',
            'Are you sure you want to delete comment #%s?',
            'Are you sure you want to delete "%d" comments?',
            'An error has occured',
            'The comment has been validated.',
            'The comments have been validated.',
            'and %s others',
        ];
    }
}
