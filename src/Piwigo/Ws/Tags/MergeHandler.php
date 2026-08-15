<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Core\WsError;
use Piwigo\Event\Album\MergeTags;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\TagService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.tags.merge` -- admin only. Merge tags in one other tag.
 */
final readonly class MergeHandler implements WsAction
{
    public function __construct(
        private TagService $tagService,
        private ActivityService $activityService,
        private EventDispatcher $eventDispatcher,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{destination_tag: int, deleted_tag: array<int, int>, images_in_merged_tag: list<int>}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = MergeParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $all_tags = $input->mergeTagIds;
        array_push($all_tags, $input->destinationTagId);

        $all_tags = array_unique($all_tags);
        $merge_tag = array_diff($input->mergeTagIds, [$input->destinationTagId]);

        if ($this->tagService->countExistingIds(array_values($all_tags)) !== count($all_tags)) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'All tags does not exist.');
        }

        $image_in_merge_tags = array_values(array_unique(
            $this->tagService->getImageIdsForTagIds(array_map(TagId::from(...), array_values($merge_tag)))
        ));

        $image_in_dest = $this->tagService->getImageIdsForTagIds([TagId::from($input->destinationTagId)]);

        $image_to_add = array_values(array_diff($image_in_merge_tags, $image_in_dest));

        $inserts = [];
        foreach ($image_to_add as $image) {
            $inserts[] = [
                'tag_id' => $input->destinationTagId,
                'image_id' => $image,
            ];
        }

        $this->tagService->copyImageTagAssociations($inserts, ignore: true);

        $this->activityService->record('tag', $input->destinationTagId, 'edit');
        foreach ($image_to_add as $image_id) {
            $this->activityService->record('photo', $image_id, 'edit', [
                'tag-add' => $input->destinationTagId,
            ]);
        }

        $this->eventDispatcher->dispatchNotify(new MergeTags(
            TagId::from($input->destinationTagId),
            array_values(array_map(TagId::from(...), $merge_tag))
        ));

        $this->tagService->deleteTags(array_values(array_map(TagId::from(...), $merge_tag)));

        $image_in_merged = array_merge($image_in_dest, $image_to_add);

        return [
            'destination_tag' => $input->destinationTagId,
            'deleted_tag' => $input->mergeTagIds,
            'images_in_merged_tag' => $image_in_merged,
        ];
    }
}
