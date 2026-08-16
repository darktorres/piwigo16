<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Exception;
use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Image\ImageService;
use Piwigo\Ws\WsAction;

/**
 * `pwg.images.exist` -- checks if an image exists by its name or md5 sum.
 */
final readonly class ExistHandler implements WsAction
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private CurrentLogger $currentLogger,
        private ImageService $imageService,
    ) {}

    /**
     * @param array<mixed> $params
     * @return array<string, int|string|null> keyed by md5sum/filename;
     *   id is 'images''s NOT NULL primary key (int|string per
     *   driver), or null when no matching photo was found
     */
    #[Override]
    public function __invoke(array $params): array
    {
        $input = ExistParams::fromArray($params);

        $logger = $this->currentLogger->get();

        $logger->debug('exist', 'WS', $params);

        $split_pattern = '/[\s,;\|]/';
        $result = [];

        if ($this->currentConfig->uniquenessMode === 'md5sum') {
            // search among photos the list of photos already added, based on md5sum list
            $md5sums = preg_split(
                $split_pattern,
                (string) $input->md5sumList,
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($md5sums === false) {
                throw new Exception('ws_images_exist(): preg_split() failed');
            }

            $id_of_md5 = $this->imageService->getIdsByMd5sums($md5sums);

            foreach ($md5sums as $md5sum) {
                $result[$md5sum] = $id_of_md5[$md5sum] ?? null;
            }
        } elseif ($this->currentConfig->uniquenessMode === 'filename') {
            // search among photos the list of photos already added, based on
            // filename list
            $filenames = preg_split(
                $split_pattern,
                (string) $input->filenameList,
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($filenames === false) {
                throw new Exception('ws_images_exist(): preg_split() failed');
            }

            $id_of_filename = $this->imageService->getIdsByFilenames($filenames);

            foreach ($filenames as $filename) {
                $result[$filename] = $id_of_filename[$filename] ?? null;
            }
        }

        return $result;
    }
}
