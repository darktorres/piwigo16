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
use Piwigo\Event\Tag\GetTagAltNames;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Event\Tag\RenderTagUrl;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tag\Projection\Tag;
use Piwigo\Tag\TagService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.tags.rename` -- admin only. Rename tag.
 */
final readonly class RenameHandler implements WsAction
{
    public function __construct(
        private TagService $tagService,
        private ActivityService $activityService,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<string, mixed>
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = RenameParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $tag_id = $input->tagId;
        $tag_name = strip_tags($input->newName);

        // does the tag exist ?
        if (! $this->tagService->existsById($tag_id)) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'This tag does not exist.');
        }

        $existing_names = $this->tagService->getOtherNames($tag_id);

        if (in_array($tag_name, $existing_names, true)) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'This name is already token');
        }

        $this->activityService->record('tag', $tag_id, 'edit');

        if ($tag_name !== '') {
            $urlNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagUrl($tag_name));
            $this->tagService->renameTag(TagId::from($tag_id), $tag_name, $urlNameEvent->tagName);
        }
        $this->entityManager->clear();

        $renamed_tag = $this->tagService->getById(TagId::from($tag_id));
        assert($renamed_tag instanceof Tag);

        $tag = [
            'id' => $renamed_tag->id->value,
            'name' => $renamed_tag->name,
            'url_name' => $renamed_tag->urlName,
        ];
        $tag['raw_name'] = $tag['name'];
        $renameNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName($tag['raw_name'], $tag));
        $tag['name'] = $renameNameEvent->tagName;
        $altNamesEvent = $this->eventDispatcher->dispatchChange(new GetTagAltNames([], $tag['raw_name']));
        $tag['alt_names'] = $altNamesEvent->value;
        return $tag;
    }
}
