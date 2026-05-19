<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Rate\RateService;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.rate` — rate an image; rejects unauthorized images. */
final readonly class RateHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private PermissionService $permissionService,
        private RateService $rateService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $pImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pRate    = is_numeric($params['rate']) ? (int) $params['rate'] : 0;
        [$ratePermSql, $ratePermParams, $ratePermTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'forbidden_images' => 'id'], '    AND');
        if (!$this->categoryRepository->isImageInVisibleCategory($pImageId, $ratePermSql, $ratePermParams, $ratePermTypes)) {
            return new PwgError(404, 'Invalid image_id or access denied');
        }
        $res = $this->rateService->ratePicture($pImageId, $pRate);
        if ($res === false) {
            return new PwgError(403, 'Forbidden or rate not in ' . implode(',', Config::rateItems()));
        }
        return $res;
    }
}
