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
        $input = CheckFilesParams::fromArray($params);
        $path  = $this->imageRepository->findPathById($input->imageId);
        if ($path === null) {
            return new PwgError(404, 'image_id not found');
        }
        $ret = [];
        if ($input->fileSum !== null) {
            $ret['file'] = md5_file($path) !== $input->fileSum ? 'differs' : 'equals';
        }
        return $ret;
    }
}
