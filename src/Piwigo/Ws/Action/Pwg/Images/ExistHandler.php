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
        $splitPattern = '/[\s,;\|]/';
        $result       = [];
        if (Config::uniquenessMode() === 'md5sum') {
            $md5sumsResult = preg_split($splitPattern, is_string($params['md5sum_list'] ?? null) ? $params['md5sum_list'] : '', -1, PREG_SPLIT_NO_EMPTY);
            $md5sums       = $md5sumsResult !== false ? $md5sumsResult : [];
            $idOfMd5       = $this->imageRepository->findIdByMd5sumMap($md5sums);
            foreach ($md5sums as $md5sum) {
                $result[$md5sum] = $idOfMd5[$md5sum] ?? null;
            }
        } elseif (Config::uniquenessMode() === 'filename') {
            $filenamesResult = preg_split($splitPattern, is_string($params['filename_list'] ?? null) ? $params['filename_list'] : '', -1, PREG_SPLIT_NO_EMPTY);
            $filenames       = $filenamesResult !== false ? $filenamesResult : [];
            $idOfFile        = $this->imageRepository->findIdByFilenameMap($filenames);
            foreach ($filenames as $filename) {
                $result[$filename] = $idOfFile[$filename] ?? null;
            }
        }
        return $result;
    }
}
