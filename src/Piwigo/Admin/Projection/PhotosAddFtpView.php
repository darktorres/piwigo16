<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `photos_add_ftp.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PhotosAddFtpPageRenderer::render()}.
 */
#[Template('photos_add_ftp.latte')]
final readonly class PhotosAddFtpView implements View
{
    public function __construct(
        public string $ftpHelpContent,
    ) {}
}
