<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Image\DerivativeSize;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.categories.refreshRepresentative` — pick a new random thumbnail. */
final readonly class RefreshRepresentativeHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private ImageAdminService $imageAdminService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $input = RefreshRepresentativeParams::fromArray($params);
        if (!$this->categoryRepository->existsById($input->categoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        if (!$this->categoryRepository->hasCategoryImages($input->categoryId)) {
            return new PwgError(401, 'not permitted');
        }
        $this->categoryAdminService->setRandomRepresentant([$input->categoryId]);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $input->categoryId, 'edit'));
        $category = $this->categoryRepository->findCategoryById($input->categoryId);
        $repId    = $category !== null && $category->representativePictureId !== null ? (string) $category->representativePictureId : '';
        return $this->imageAdminService->getCategoryRepresentantProperties($repId, DerivativeSize::Small->value);
    }
}
