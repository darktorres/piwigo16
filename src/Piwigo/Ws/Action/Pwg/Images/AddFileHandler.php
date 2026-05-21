<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Upload\UploadService;
use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Image\ImageRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.addFile` — replace an image's original file after chunks were uploaded. */
final readonly class AddFileHandler implements WsAction
{
    public function __construct(
        private ImageRepository $imageRepository,
        private UploadService $uploadService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $logger = LoggerRegistry::current();
        $logger->debug(__FUNCTION__, $params);
        $pImageId = AddFileParams::fromArray($params)->imageId;
        $image    = $this->imageRepository->findById($pImageId);
        if ($image === null) {
            return new PwgError(404, 'image_id not found');
        }
        $imageMd5sum = $image->md5sum !== null ? $image->md5sum->value : '';
        $imageFile   = $image->file->value;
        $filePath    = Config::uploadDir() . '/buffer/' . $imageMd5sum . '-original';
        $mergeError  = $this->uploadService->mergeChunks($filePath, $imageMd5sum, 'file');
        if ($mergeError !== null) {
            return $mergeError;
        }
        chmod($filePath, Config::chmodValue() & 0o666);
        $infos    = $this->uploadService->pwgImageInfos($filePath);
        $doUpdate = ($infos->width > ($image->width ?? 0))
                 || ($infos->height > ($image->height ?? 0))
                 || ($infos->filesize > ($image->filesize ?? 0));
        if (!$doUpdate) {
            unlink($filePath);
            return true;
        }
        $this->uploadService->addUploadedFile($filePath, $imageFile, null, null, $pImageId, $imageMd5sum);
        return null;
    }
}
