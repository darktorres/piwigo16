<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Override;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageService;
use Piwigo\Image\Projection\UploadInfo;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.addFile` -- admin only. Adds or updates a file for an
 * existing photo. `pwg.images.addChunk` must have been called before
 * (maybe several times).
 */
final readonly class AddFileHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private UploadService $uploadService,
        private CurrentConfig $currentConfig,
        private CurrentLogger $currentLogger,
        private Paths $paths,
        private UrlServiceInterface $urlService,
        private ChunkedUploadHelper $chunkedUploadHelper,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|bool|null
    {
        $input = AddFileParams::fromArray($params);

        $logger = $this->currentLogger->get();

        $logger->debug('addFile', 'WS', $params);

        // what is the path and other infos about the photo?
        $image = $this->imageService->getUploadInfoById(ImageId::from($input->imageId));
        if (! $image instanceof UploadInfo) {
            return new WsErrorResponse(404, 'image_id not found');
        }

        // this legacy chunked-upload flow locates buffered chunks by md5sum, so
        // it cannot proceed for a photo that has none (e.g. added before the
        // md5sum feature was enabled, see pwg.images.setMd5sum).
        if (! is_string($image->md5sum)) {
            return new WsErrorResponse(500, '[ws_images_addFile] image_id ' . $input->imageId . ' has no md5sum');
        }

        // since Piwigo 2.4 and derivatives, we do not take the imported "thumb" into account
        if ($input->type === 'thumb') {
            $this->chunkedUploadHelper->removeChunks($image->md5sum, $input->type);
            return true;
        }

        // since Piwigo 2.4 and derivatives, we only care about the "original"
        $original_type = 'file';
        if ($input->type === 'high') {
            $original_type = 'high';
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir;
        $file_path = $upload_dir_conf . '/buffer/' . $image->md5sum . '-original';

        $this->chunkedUploadHelper->mergeChunks($file_path, $image->md5sum, $original_type);
        chmod($file_path, 0644);

        // if we receive the "file", we only update the original if the "file" is
        // bigger than current original
        if ($input->type === 'file') {
            $do_update = false;

            $infos = $this->uploadService
                ->pwgImageInfos($file_path);

            $imageArr = $image->toArray();
            if ($infos->width > $imageArr['width']
                || $infos->height > $imageArr['height']
                || $infos->filesize > $imageArr['filesize']) {
                $do_update = true;
            }

            if (! $do_update) {
                unlink($file_path);
                return true;
            }
        }

        $this->uploadService
            ->addUploadedFile(
                $file_path,
                $this->urlService,
                $image->file,
                null,
                null,
                $input->imageId,
                $image->md5sum, // we force the md5sum to remain the same
            );

        return null;
    }
}
