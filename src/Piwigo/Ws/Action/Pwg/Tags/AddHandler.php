<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Tag\TagRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsParamException;

/** `pwg.tags.add` — create a new tag. */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private TagAdminService $tagAdminService,
        private TagRepository $tagRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): AddResult|PwgError
    {
        try {
            $input = AddParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(WsError::InvalidParam->value, $e->getMessage());
        }
        $result = $this->tagAdminService->createTag($input->name);
        if ($result->isError) {
            return new PwgError(WsError::InvalidParam->value, $result->error ?? '');
        }
        $tagAddId = $result->id ?? 0;
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $tagAddId, ActivityAction::Add));
        $newTag = $this->tagRepository->findById($tagAddId);
        return new AddResult(
            info:    $result->info ?? '',
            id:      $tagAddId,
            name:    $newTag !== null ? $newTag->name : '',
            urlName: $newTag !== null ? $newTag->urlName : '',
        );
    }
}
