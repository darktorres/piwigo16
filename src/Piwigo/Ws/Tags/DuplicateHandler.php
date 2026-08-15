<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Core\WsError;
use Piwigo\Event\Tag\RenderTagUrl;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\TagService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.tags.duplicate` -- admin only. Creates a copy of a tag.
 *
 * id/name/count are real (id is explicitly (int)-cast from
 * lastInsertId(), name is the mandatory `copyName`, count is
 * count($inserts)).
 */
final readonly class DuplicateHandler implements WsAction
{
    public function __construct(
        private TagService $tagService,
        private ActivityService $activityService,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{id: int, name: string, url_name: string, count: int}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = DuplicateParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $tag_id = $input->tagId;
        $copy_name = $input->copyName;

        // does the tag exist ?
        if (! $this->tagService->existsById($tag_id)) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'This tag does not exist.');
        }

        if ($this->tagService->existsByName($copy_name)) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'This name is already taken.');
        }

        $copyUrlNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagUrl($copy_name));
        $copy_url_name = $copyUrlNameEvent->tagName;
        $destination_tag_id = $this->tagService->duplicateTag($copy_name, $copy_url_name)
            ->value;
        $this->entityManager->clear();

        $this->activityService->record('tag', $destination_tag_id, 'add', [
            'action' => 'duplicate',
            'source_tag' => $tag_id,
        ]);

        $destination_tag_image_ids = $this->tagService->getImageIdsForTagIds([TagId::from($tag_id)]);

        $inserts = [];

        foreach ($destination_tag_image_ids as $image_id) {
            $inserts[] = [
                'tag_id' => $destination_tag_id,
                'image_id' => $image_id,
            ];
            $this->activityService->record('photo', $image_id, 'edit', [
                'add-tag' => $destination_tag_id,
            ]);
        }

        if (count($inserts) > 0) {
            $this->tagService->copyImageTagAssociations($inserts);
        }

        return [
            'id' => $destination_tag_id,
            'name' => $copy_name,
            'url_name' => $copy_url_name,
            'count' => count($inserts),
        ];
    }
}
