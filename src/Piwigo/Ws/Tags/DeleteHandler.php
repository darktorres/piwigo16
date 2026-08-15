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
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Core\WsError;
use Piwigo\Tag\TagService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.tags.delete` -- admin only. Delete tag(s) by ID.
 */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private TagService $tagService,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{id: list<int>}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = DeleteParams::fromArray($params);

        $csrfError = $this->wsHelper->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if ($this->tagService->countExistingIds($input->tagIds) !== count($input->tagIds)) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'All tags does not exist.');
        }

        $tag_ids = $input->tagIds;

        if (count($tag_ids) > 0) {
            $this->tagService->deleteTags(array_map(TagId::from(...), $input->tagIds));
            return [
                'id' => $tag_ids,
            ];
        } else {
            return [
                'id' => [],
            ];
        }
    }
}
