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
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Tag\TagService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.tags.delete` -- admin only. Delete tag(s) by ID.
 */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private TagService $tagService,
        private WsCsrfGuard $wsCsrfGuard,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{id: list<int>}
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = DeleteParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if ($this->tagService->countExistingIds($input->tagIds) !== count($input->tagIds)) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'All tags does not exist.');
        }

        $tag_ids = $input->tagIds;

        if (count($tag_ids) > 0) {
            $this->tagService->deleteTags(array_map(TagId::from(...), $input->tagIds), $this->entityManager);
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
