<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Image;

use Piwigo\Core\UrlServiceInterface;
use Piwigo\Users\CurrentUser;

/**
 * Returns an array map of urls (thumb/element) for image_row -- a shared
 * shape used across several `/api/v1/images*`-family endpoints
 * (`ImageGetController`, `ImageSearchController`, `FavoriteListController`,
 * `CategoryImagesController`, `TagImagesController`). Moved here from
 * `Piwigo\Ws` (P25 Stage 1 step 6 first split it out of the former
 * WsHelper god-class; P27 moved it again when the WS layer itself was
 * deleted, since it turned out to be real, live domain logic every REST
 * family above still needs, not WS-protocol-specific).
 */
final readonly class ImageUrlBuilder
{
    public function __construct(
        private CurrentUser $currentUser,
    ) {}

    /**
     * $image_row is genuinely arbitrary by design (built into a SrcImage
     * below, the same cross-domain generic-row-reader shape SrcImage's own
     * docblock documents across its ~17 real construction sites).
     *
     * @param array<string, mixed> $image_row
     * @return array{page_url: string, element_url?: string, download_url: ?string, derivatives: array<string, array{url: string, width: int, height: int}>}
     */
    public function stdGetUrls(array $image_row, UrlServiceInterface $urlService): array
    {
        $ret = [];

        $ret['page_url'] = $urlService->makePictureUrl(
            [
                'image_id' => $image_row['id'],
                'image_file' => $image_row['file'],
            ]
        );

        $src_image = new SrcImage($image_row);

        $provide_download_url = false;

        if ($src_image->isOriginal()) {// we have a photo
            if ($this->currentUser->get()->enabledHigh) {
                $ret['element_url'] = $src_image->getUrl();
                $provide_download_url = true;
            }
        } else {
            $ret['element_url'] = $urlService->getElementUrl($image_row);
            $provide_download_url = true;
        }

        $ret['download_url'] = null;
        if ($provide_download_url) {
            $image_id = $image_row['id'];
            if (is_int($image_id) || is_string($image_id)) {
                $ret['download_url'] = $urlService->getActionUrl($image_id, 'e', true);
            }
        }

        $derivatives = DerivativeImage::getAll($src_image);
        $derivatives_arr = [];
        foreach ($derivatives as $type => $derivative) {
            $size = $derivative->getSize();
            if ($size === null) {
                $size = [null, null];
            }
            $derivatives_arr[(string) $type] = [
                'url' => $derivative->getUrl(),
                'width' => (int) $size[0],
                'height' => (int) $size[1],
            ];
        }
        $ret['derivatives'] = $derivatives_arr;
        return $ret;
    }
}
