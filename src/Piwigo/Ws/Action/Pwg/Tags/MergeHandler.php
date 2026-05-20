<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Album\MergeTags;
use Piwigo\Tag\TagRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsParamException;
use Psr\EventDispatcher\EventDispatcherInterface;

/** `pwg.tags.merge` — fold N tags' image associations into a destination tag, then delete them. */
final readonly class MergeHandler implements WsAction
{
    public function __construct(
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private EventDispatcherInterface $dispatcher,
        private TagAdminService $tagAdminService,
        private TagRepository $tagRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): MergeResult|PwgError
    {
        try {
            $input = MergeParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $destId   = $input->destinationTagId;
        $tagIds   = $input->mergeTagIds;
        $allTags  = array_values(array_unique(array_merge($tagIds, [$destId])));
        $mergeTag = array_values(array_diff($tagIds, [$destId]));
        if ($this->tagRepository->countByIds($allTags) !== count($allTags)) {
            return new PwgError(WsError::InvalidParam->value, 'All tags does not exist.');
        }
        $imageInMergeTags = $this->tagRepository->findDistinctImageIdsByTagIds($mergeTag);
        $imageInDest      = $this->tagRepository->findImageIdsByTagId($destId);
        $imageToAdd       = array_values(array_diff($imageInMergeTags, $imageInDest));
        $inserts          = [];
        foreach ($imageToAdd as $image) {
            $inserts[] = ['tag_id' => $destId, 'image_id' => $image];
        }
        $this->tagRepository->insertImageTagsBatch($inserts, true);
        $this->activityLogger->log(new ActivityEvent(ActivityObject::Tag, $destId, 'edit'));
        foreach ($imageToAdd as $imageId) {
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $imageId, 'edit', ['tag-add' => $destId]));
        }
        $this->dispatcher->dispatch(new MergeTags($destId, $mergeTag));
        $this->tagAdminService->deleteTags($mergeTag);
        return new MergeResult(
            destinationTag:    $destId,
            deletedTagIds:     $tagIds,
            imagesInMergedTag: array_values(array_merge($imageInDest, $imageToAdd)),
        );
    }
}
