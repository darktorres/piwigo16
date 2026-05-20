<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.categories.deleteRepresentative` — clear an album's thumbnail (allow_random_representative only). */
final readonly class DeleteRepresentativeHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CategoryRepository $categoryRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        $input      = DeleteRepresentativeParams::fromArray($params);
        $categoryId = $input->categoryId;
        if (!$this->categoryRepository->existsById($categoryId)) {
            return new PwgError(404, 'category_id not found');
        }
        $nbImages = $this->categoryRepository->countImagesByCategoryId($categoryId);
        if (!Config::allowRandomRepresentative() && $nbImages !== 0) {
            return new PwgError(401, 'not permitted');
        }
        $this->categoryRepository->clearRepresentatives([$categoryId]);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $categoryId, ActivityAction::Edit));
        return null;
    }
}
