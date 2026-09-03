<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `photos_add_ftp.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PhotosAddFtpPageRenderer::render()}.
 *
 * `$ftpHelpContent` is Html, not string (P59): a local, fixed-filename
 * `help/photos_add_ftp.html` file shipped with the app, never
 * user-supplied text.
 */
#[Template('photos_add_ftp.latte')]
final readonly class PhotosAddFtpView implements View
{
    public function __construct(
        public Html $ftpHelpContent,
    ) {}
}
