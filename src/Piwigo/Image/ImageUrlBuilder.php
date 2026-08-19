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
use Piwigo\Image\Event\GetHighUrl;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;

/**
 * Returns an array map of urls (thumb/element) for image_row -- a shared
 * shape used across several `/api/v1/images*`-family endpoints
 * (`ImageGetController`, `ImageSearchController`, `FavoriteListController`,
 * `CategoryImagesController`, `TagImagesController`).
 */
final readonly class ImageUrlBuilder
{
    public function __construct(
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
    ) {}

    /**
     * $image_row stays a raw array, not SrcImageInfo -- it's a shared
     * shape read by several unrelated /api/v1/images*-family callers
     * beyond just the SrcImageInfo::fromRow() conversion below
     * (`image_id`/`image_file`/UrlServiceInterface::getElementUrl()'s own
     * whole-row param), so the array-to-object boundary conversion
     * belongs right at the SrcImage construction point, not this
     * method's own public signature.
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

        $src_image = new SrcImage(SrcImageInfo::fromRow($image_row));

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
                $downloadUrl = $urlService->getActionUrl($image_id, 'e', true);
                $ret['download_url'] = $this->eventDispatcher->dispatch(new GetHighUrl($downloadUrl, $src_image))->url;
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
