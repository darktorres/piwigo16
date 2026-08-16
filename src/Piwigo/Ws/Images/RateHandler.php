<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\Rate\RateService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.rate` -- rates an image.
 */
final readonly class RateHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private PermissionService $permissionService,
        private RateService $rateService,
        private CurrentConfig $currentConfig,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<string, mixed> matches
     *   Rate\RateService::rate()'s own already-reviewed by-design shape
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = RateParams::fromArray($params);

        $accessible = $this->imageService->isImageAccessibleWithCondition(
            ImageId::from($input->imageId),
            $this->permissionService->getPermissionCriteria()
        );
        if (! $accessible) {
            return new WsErrorResponse(404, 'Invalid image_id or access denied');
        }

        $res = $this->rateService
            ->rate($input->imageId, (int) $input->rate, $this->entityManager);

        if ($res === false) {
            $rate_items = $this->currentConfig->rateItems;
            return new WsErrorResponse(403, 'Forbidden or rate not in ' . implode(',', $rate_items));
        }
        return $res;
    }
}
