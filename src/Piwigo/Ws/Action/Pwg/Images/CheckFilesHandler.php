<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Image\ImageRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.checkFiles` — report differs/equals against client-side md5 for a photo. */
final readonly class CheckFilesHandler implements WsAction
{
    public function __construct(
        private ImageRepository $imageRepository,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, string>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $checkImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $path         = $this->imageRepository->findPathById($checkImageId);
        if ($path === null) {
            return new PwgError(404, 'image_id not found');
        }
        $ret = [];
        if (isset($params['file_sum'])) {
            $ret['file'] = md5_file($path) !== $params['file_sum'] ? 'differs' : 'equals';
        }
        return $ret;
    }
}
