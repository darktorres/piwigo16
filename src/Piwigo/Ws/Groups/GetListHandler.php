<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Groups;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\Projection\GroupListing;
use Piwigo\Sort\GroupSortField;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.groups.getList` -- returns the list of groups, optionally filtered.
 */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{paging: NamedStruct, groups: NamedArray}
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        return $this->resolve($params);
    }

    /**
     * Callable directly by any other handler that needs this method's own
     * result without going through Server::invoke()'s string-keyed
     * dispatch (P25 Stage 1's recursive-dispatch removal) -- $params uses
     * the same raw wire shape GetListParams::fromArray() always expected,
     * so a caller building this array itself must array-wrap any
     * FORCE_ARRAY-flagged key (group_id) exactly as Server::invoke()'s own
     * coercion would have.
     *
     * @param array<mixed> $params
     * @return WsErrorResponse|array{paging: NamedStruct, groups: NamedArray}
     */
    public function resolve(array $params): WsErrorResponse|array
    {
        $input = GetListParams::fromArray($params);

        $order = GroupSortField::parseOrderClause($input->order);
        if ($order === null) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid input parameter order');
        }

        $groups = $this->entityManager->getRepository(GroupEntity::class)
            ->findWithMemberCounts(
                array_map(GroupId::from(...), $input->groupIds),
                $input->name,
                $order,
                $input->perPage,
                $input->page
            );

        return [
            'paging' => new NamedStruct([
                'page' => $input->page,
                'per_page' => $input->perPage,
                'count' => count($groups),
            ]),
            'groups' => new NamedArray(array_map(
                static fn (GroupListing $g): array => $g->toArray(),
                $groups
            ), 'group'),
        ];
    }
}
