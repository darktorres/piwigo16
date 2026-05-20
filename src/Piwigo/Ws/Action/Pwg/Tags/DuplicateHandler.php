<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Tag\RenderTagUrl;
use Piwigo\Tag\TagRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsParamException;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.tags.duplicate` — clone a tag (incl. image associations) under a new name. */
final readonly class DuplicateHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private EventDispatcherInterface $dispatcher,
        private TagRepository $tagRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): DuplicateResult|PwgError
    {
        try {
            $input = DuplicateParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($this->tagRepository->countById($input->tagId) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'This tag does not exist.');
        }
        if ($this->tagRepository->countByExactName($input->copyName) !== 0) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already taken.');
        }
        $urlEvent = new RenderTagUrl($input->copyName);
        $this->dispatcher->dispatch($urlEvent);
        $urlName          = $urlEvent->tagName;
        $destinationTagId = $this->tagRepository->insertNewTag(['name' => $input->copyName, 'url_name' => $urlName]);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $destinationTagId, ActivityAction::Add, ['action' => 'duplicate', 'source_tag' => $input->tagId]));
        $destinationTagImageIds = $this->tagRepository->findImageIdsByTagId($input->tagId);
        $inserts                = [];
        foreach ($destinationTagImageIds as $imageId) {
            $inserts[] = ['tag_id' => $destinationTagId, 'image_id' => $imageId];
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $imageId, ActivityAction::Edit, ['add-tag' => $destinationTagId]));
        }
        $this->tagRepository->insertImageTagsBatch($inserts, false);
        return new DuplicateResult(
            id:      $destinationTagId,
            name:    $input->copyName,
            urlName: $urlName,
            count:   count($inserts),
        );
    }
}
