<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `photos_add_applications.latte`'s own typed view, constructed by
 * {@see \Piwigo\Admin\PhotosAddApplicationsPageRenderer::render()}.
 * Empty -- the "applications" tab is entirely static markup, no
 * page-specific data.
 */
#[Template('photos_add_applications.latte')]
final readonly class PhotosAddApplicationsView implements View {}
