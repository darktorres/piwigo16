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
        $creationOutput = $this->tagAdminService->createTag($input->name);
        if (isset($creationOutput['error'])) {
            return new PwgError(WsError::InvalidParam->value, is_string($creationOutput['error']) ? $creationOutput['error'] : '');
        }
        $tagAddId = is_numeric($creationOutput['id'] ?? null) ? (int) $creationOutput['id'] : 0;
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $tagAddId, 'add'));
        $newTag = $this->tagRepository->findById($tagAddId);
        return new AddResult(
            info:    is_string($creationOutput['info'] ?? null) ? $creationOutput['info'] : '',
            id:      $tagAddId,
            name:    $newTag !== null ? $newTag->name : '',
            urlName: $newTag !== null ? $newTag->urlName : '',
        );
    }
}
