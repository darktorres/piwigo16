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
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Core\ValidationPattern;
use Piwigo\Core\WsError;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\Projection\GroupListing;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\NamedStruct;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
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
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = GetListParams::fromArray($params);

        if (! (bool) preg_match(ValidationPattern::ORDER, $input->order)) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid input parameter order');
        }

        $groups = $this->entityManager->getRepository(GroupEntity::class)
            ->findWithMemberCounts(
                array_map(GroupId::from(...), $input->groupIds),
                $input->name,
                $input->order,
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
