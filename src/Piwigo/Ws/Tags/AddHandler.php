<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Piwigo\Activity\ActivityService;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Core\WsError;
use Piwigo\Tag\TagService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.tags.add` -- admin only. Adds a new tag.
 *
 * Known limitation: one tag per call -- batch creation would need a
 * param-shape change (a list instead of a single `name`), deliberate
 * current API shape, not a defect.
 */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private TagService $tagService,
        private ActivityService $activityService,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{info: string, id: int|string, name: string, url_name: string}
     */
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = AddParams::fromArray($params);
        $creation_output = $this->tagService->createTag($input->name);

        if ($creation_output->error !== null) {
            return new WsErrorResponse(WsError::INVALID_PARAM, $creation_output->error);
        }

        // success()'s own contract guarantees info/id are non-null whenever
        // error is null (just checked above).
        $creation_id = $creation_output->id ?? 0;
        $creation_info = $creation_output->info ?? '';

        $this->activityService->record('tag', $creation_id, 'add');

        $new_tag = $this->tagService->getById(TagId::from($creation_id));

        return [
            'info' => $creation_info,
            'id' => $creation_id,
            'name' => $new_tag->name ?? '',
            'url_name' => $new_tag->urlName ?? '',
        ];
    }
}
