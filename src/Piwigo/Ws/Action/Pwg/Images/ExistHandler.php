<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Config\Config;
use Piwigo\Image\ImageRepository;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.exist` — for each md5 / filename, return its image_id or null (uniqueness_mode dependent). */
final readonly class ExistHandler implements WsAction
{
    public function __construct(
        private ImageRepository $imageRepository,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, int|null>
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): array
    {
        $input  = ExistParams::fromArray($params);
        $result = [];
        if (Config::uniquenessMode() === 'md5sum') {
            $idOfMd5 = $this->imageRepository->findIdByMd5sumMap($input->md5sums);
            foreach ($input->md5sums as $md5sum) {
                $result[$md5sum] = $idOfMd5[$md5sum] ?? null;
            }
        } elseif (Config::uniquenessMode() === 'filename') {
            $idOfFile = $this->imageRepository->findIdByFilenameMap($input->filenames);
            foreach ($input->filenames as $filename) {
                $result[$filename] = $idOfFile[$filename] ?? null;
            }
        }
        return $result;
    }
}
