<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Tag\TagRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.tags.add` — create a new tag. */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private TagAdminService $tagAdminService,
        private TagRepository $tagRepository,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $creationOutput = $this->tagAdminService->createTag(is_string($params['name'] ?? null) ? $params['name'] : '');
        if (isset($creationOutput['error'])) {
            return new PwgError(WsError::InvalidParam->value, is_string($creationOutput['error']) ? $creationOutput['error'] : '');
        }
        $tagAddId = is_numeric($creationOutput['id'] ?? null) ? (int) $creationOutput['id'] : 0;
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $tagAddId, 'add'));
        $newTagRow = $this->tagRepository->findById($tagAddId);
        return ['info' => $creationOutput['info'], 'id' => $creationOutput['id'], 'name' => $newTagRow['name'] ?? '', 'url_name' => $newTagRow['url_name'] ?? ''];
    }
}
