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
use Piwigo\Config\CurrentConfig;
use Piwigo\Csrf\CsrfService;
use Piwigo\Image\ImageService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.setMd5sum` -- admin only. Adds md5sum at photos, by
 * block. Returns how many md5sums were added and how many are
 * remaining.
 */
final readonly class SetMd5sumHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{nb_added: int, nb_no_md5sum: int}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = SetMd5sumParams::fromArray($params);

        if (new CsrfService($this->currentConfig)->getToken() !== $input->pwgToken) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $imageService = $this->imageService;

        $no_md5sum_ids = $imageService->getPhotosNoMd5sum();
        $added_count = 0;

        if (count($no_md5sum_ids) > 0) {
            $md5sum_ids_to_add = array_slice($no_md5sum_ids, 0, $input->blockSize);
            $added_count = $imageService->addMd5sum($md5sum_ids_to_add);
        }

        return [
            'nb_added' => $added_count,
            'nb_no_md5sum' => count($imageService->getPhotosNoMd5sum()),
        ];
    }
}
