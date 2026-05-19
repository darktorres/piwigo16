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
        $destId   = is_numeric($params['destination_tag_id']) ? (int) $params['destination_tag_id'] : 0;
        $tagIds   = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['merge_tag_id']) ? $params['merge_tag_id'] : []);
        $allTags  = array_unique(array_merge($tagIds, [$destId]));
        $mergeTag = array_diff($tagIds, [$destId]);
        if ($this->tagRepository->countByIds($allTags) !== count($allTags)) {
            return new PwgError(WsError::InvalidParam->value, 'All tags does not exist.');
        }
        $imageInMergeTags = $this->tagRepository->findDistinctImageIdsByTagIds($mergeTag);
        $imageInDest      = $this->tagRepository->findImageIdsByTagId($destId);
        $imageToAdd       = array_diff($imageInMergeTags, $imageInDest);
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
        return ['destination_tag' => $destId, 'deleted_tag' => $tagIds, 'images_in_merged_tag' => array_merge($imageInDest, $imageToAdd)];
    }
}
