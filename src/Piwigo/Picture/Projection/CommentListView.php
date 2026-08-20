<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
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
 * `$rootUrl`/`$iconDir` are the ambient `$ROOT_URL`/`$themeconf['icon_dir']`
 * the template's own `error_icon` `exposeData` call reads; only
 * meaningful when `$commentDerivativeParams` is non-null, matching the
 * original `{if isset($commentDerivativeParams)}` guard exactly.
 */
#[Template('comment_list.latte')]
final readonly class CommentListView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<array<string, mixed>> $comments
     */
    public function __construct(
        public array $comments,
        public ?DerivativeParams $commentDerivativeParams,
        public string $rootUrl,
        public string $iconDir,
    ) {}

    /**
     * `comment_list.latte`'s own unconditional `{do combineCss(...)}`
     * plus its two `{if !$derivative->isCached()}`-gated
     * `{do combineScript(...)}` calls, registered unconditionally here
     * -- `$derivative->isCached()` needs a per-comment `$pwg->
     * derivative(...)` service call this DTO View has no access to;
     * `PageAssets::add()` is dedup-safe, so an always-registered script
     * that goes unused on a page where every derivative happens to
     * already be cached is a safe, deliberate widening, not a
     * correctness break (docs/PLAN.md's P42-B). Its third conditional
     * `{if isset($comment['U_DELETE'])}` call IS fully derivable from
     * `$comments` without any service call, so it stays a real
     * conditional.
     */
    #[Override]
    public function pageAssets(): array
    {
        $assets = [
            AssetContribution::css('themes/default/css/pages/comment_list.css', id: 'comment_list'),
            AssetContribution::script('jquery.ajaxmanager', 'themes/default/js/plugins/jquery.ajaxmanager.js', loadMode: LoadMode::Footer),
            AssetContribution::script('thumbnails.loader', 'themes/default/js/thumbnails.loader.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ajaxmanager', 'page-data']),
        ];

        foreach ($this->comments as $comment) {
            if (isset($comment['U_DELETE'])) {
                $assets[] = AssetContribution::script('core.scripts', 'themes/default/js/scripts.js', loadMode: LoadMode::Footer, dependsOn: ['page-data']);
                break;
            }
        }

        return $assets;
    }

    #[Override]
    public function exposedPageData(): array
    {
        if ($this->commentDerivativeParams === null) {
            return [];
        }

        return [
            'error_icon' => $this->rootUrl . $this->iconDir . '/errors_small.png',
        ];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
