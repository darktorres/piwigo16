<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Image\DerivativeImage;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `picture_content.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\PictureController::defaultPictureContent()}.
 * Rendered as a plain string (`Renderer::render()`'s own `Html` cast)
 * into `RenderElementContent::$content`, not appended onto `Template::
 * $output` directly -- see that method's own docblock. `$rootUrl`/
 * `$iconDir` are the ambient `$ROOT_URL`/`$themeconf['icon_dir']` the
 * template's own `error_icon` `exposeData` call reads.
 */
#[Template('picture_content.latte')]
final readonly class PictureContentView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, mixed> $current
     */
    public function __construct(
        public ?string $uOriginal,
        public string $altImg,
        public string $cookiePath,
        public ?int $pdfViewerFilesizeThreshold,
        public array $current,
        public string $rootUrl,
        public string $iconDir,
    ) {}

    /**
     * `picture_content.latte`'s own `{if !$current['selected_derivative']->
     * isCached()}`-gated `{do combineScript(...)}` pair -- unlike
     * `CommentListView`/`CategoryCatsView`/`ThumbnailsView`'s own
     * identical-looking pattern, `$current['selected_derivative']` is
     * already a real, fully-constructed `DerivativeImage` sitting on
     * this View's own constructor data (not a per-item `$pwg->
     * derivative(...)` service call), so this stays a real, exact
     * conditional -- no widening needed (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        if ($this->isSelectedDerivativeCached()) {
            return [];
        }

        return [
            AssetContribution::script('jquery.ajaxmanager', 'themes/default/js/plugins/jquery.ajaxmanager.js', loadMode: LoadMode::Footer),
            AssetContribution::script('thumbnails.loader', 'themes/default/js/thumbnails.loader.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.ajaxmanager', 'page-data']),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        if ($this->isSelectedDerivativeCached()) {
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

    private function isSelectedDerivativeCached(): bool
    {
        $selectedDerivative = $this->current['selected_derivative'] ?? null;

        return ! $selectedDerivative instanceof DerivativeImage || $selectedDerivative->isCached();
    }
}
