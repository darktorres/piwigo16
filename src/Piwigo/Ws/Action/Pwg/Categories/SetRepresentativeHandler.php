<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Activity\ActivityAction;
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
        $input = SetRepresentativeParams::fromArray($params);
        if (!$this->categoryRepository->existsById($input->categoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        if (!$this->imageRepository->existsById($input->imageId)) {
            return new PwgError(404, 'image_id not found');
        }
        $this->categoryRepository->setRepresentativePicture([$input->categoryId], $input->imageId);
        $this->userRepository->clearUserRepresentativeForCategory($input->categoryId);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $input->categoryId, ActivityAction::Edit, ['image_id' => $input->imageId]));
        return null;
    }
}
