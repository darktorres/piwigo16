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
        $input = RateParams::fromArray($params);
        [$ratePermSql, $ratePermParams, $ratePermTypes] = $this->permissionService->getSqlConditionFandF(['forbidden_categories' => 'category_id', 'forbidden_images' => 'id'], '    AND');
        if (!$this->categoryRepository->isImageInVisibleCategory($input->imageId, $ratePermSql, $ratePermParams, $ratePermTypes)) {
            return new PwgError(404, 'Invalid image_id or access denied');
        }
        $res = $this->rateService->ratePicture($input->imageId, $input->rate);
        if ($res === false) {
            return new PwgError(403, 'Forbidden or rate not in ' . implode(',', Config::rateItems()));
        }
        return $res;
    }
}
