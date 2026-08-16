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
use Piwigo\Image\ImageService;
use Piwigo\Ws\WsAction;

/**
 * `pwg.images.emptyLounge` -- empties the lounge, where photos may wait
 * before taking off.
 *
 * @since 12
 */
final readonly class EmptyLoungeHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return array{rows: list<array{image_id: int, category_id: int}>|null} matches
     *   ImageService::emptyLounge()'s own already-precise return type
     */
    #[Override]
    public function __invoke(array $params): array
    {
        $ret = [
            'rows' => $this->imageService
                ->emptyLounge(),
        ];

        return $ret;
    }
}
