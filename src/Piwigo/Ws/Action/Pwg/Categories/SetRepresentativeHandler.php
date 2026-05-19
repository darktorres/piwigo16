<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Category\CategoryRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Users\UserRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.categories.setRepresentative` — pin a specific image as a category's thumbnail. */
final readonly class SetRepresentativeHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CategoryRepository $categoryRepository,
        private ImageRepository $imageRepository,
        private UserRepository $userRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $categoryId = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
        $imageId    = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        if (!$this->categoryRepository->existsById($categoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        if (!$this->imageRepository->existsById($imageId)) {
            return new PwgError(404, 'image_id not found');
        }
        $this->categoryRepository->setRepresentativePicture([$categoryId], $imageId);
        $this->userRepository->clearUserRepresentativeForCategory($categoryId);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $categoryId, 'edit', ['image_id' => $imageId]));
        return null;
    }
}
