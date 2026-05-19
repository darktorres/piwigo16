<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

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

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $tagId    = is_numeric($params['tag_id']) ? (int) $params['tag_id'] : 0;
        $copyName = is_string($params['copy_name'] ?? null) ? $params['copy_name'] : '';
        if ($this->tagRepository->countById($tagId) === 0) {
            return new PwgError(WsError::InvalidParam->value, 'This tag does not exist.');
        }
        if ($this->tagRepository->countByExactName($copyName) !== 0) {
            return new PwgError(WsError::InvalidParam->value, 'This name is already taken.');
        }
        $urlEvent = new RenderTagUrl($copyName);
        $this->dispatcher->dispatch($urlEvent);
        $urlName          = $urlEvent->tagName;
        $destinationTagId = $this->tagRepository->insertNewTag(['name' => $copyName, 'url_name' => $urlName]);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $destinationTagId, 'add', ['action' => 'duplicate', 'source_tag' => $tagId]));
        $destinationTagImageIds = $this->tagRepository->findImageIdsByTagId($tagId);
        $inserts                = [];
        foreach ($destinationTagImageIds as $imageId) {
            $inserts[] = ['tag_id' => $destinationTagId, 'image_id' => $imageId];
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $imageId, 'edit', ['add-tag' => $destinationTagId]));
        }
        $this->tagRepository->insertImageTagsBatch($inserts, false);
        return ['id' => $destinationTagId, 'name' => $copyName, 'url_name' => $urlName, 'count' => count($inserts)];
    }
}
