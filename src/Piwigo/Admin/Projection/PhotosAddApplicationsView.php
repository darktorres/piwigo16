<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `photos_add_applications.latte`'s own typed view, constructed by
 * {@see \Piwigo\Admin\PhotosAddApplicationsPageRenderer::render()}.
 * Empty -- the "applications" tab is entirely static markup, no
 * page-specific data.
 */
#[Template('photos_add_applications.latte')]
final readonly class PhotosAddApplicationsView implements View, HasPageAssets
{
    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            ...new ColorboxView()
                ->pageAssets(),
            AssetContribution::script('photos_add_applications', 'themes/admin/default/js/photos_add_applications.js', loadMode: LoadMode::Footer, dependsOn: ['jquery.colorbox']),
            AssetContribution::css('themes/admin/default/css/pages/photos_add_applications.css', id: 'photos_add_applications'),
        ];
    }
}
