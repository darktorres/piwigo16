<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\AppInfo;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `photos_add_applications.latte`'s own typed view, constructed by
 * {@see \Piwigo\Admin\PhotosAddApplicationsPageRenderer::render()}.
 * The "applications" tab is otherwise entirely static markup; the one
 * piece of data is the upstream host every screenshot and extension
 * link on it points at, which the template used to hardcode as
 * `piwigo.org` -- 9 live third-party image fetches per page view, in a
 * fork whose {@see AppInfo::DOMAIN} exists precisely so it never calls
 * the real upstream server. Threaded through the same
 * `phpwgUrl: AppInfo::URL` way `photos_add_direct.latte` and
 * `maintenance_env.latte` already receive it.
 */
#[Template('photos_add_applications.latte')]
final readonly class PhotosAddApplicationsView implements View, HasPageAssets
{
    public function __construct(
        public string $phpwgUrl,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            ...new ColorboxView()
                ->pageAssets(),
            AssetContribution::script('photos_add_applications', 'themes/admin/default/js/photos_add_applications.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/photos_add_applications.css', id: 'photos_add_applications'),
        ];
    }
}
