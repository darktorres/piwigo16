<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Core\ValidationPattern;
use Piwigo\Group\GroupRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.groups.getList` — list groups with optional filtering. */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private GroupRepository $groupRepository,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $input = GetListParams::fromArray($params);
        if (!preg_match(ValidationPattern::ORDER, $input->order)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter order');
        }
        $whereClauses = [];
        $listParams   = [];
        $listTypes    = [];
        if ($input->name !== null) {
            $whereClauses[] = 'LOWER(name) LIKE ?';
            $listParams[]   = $input->name;
            $listTypes[]    = \Doctrine\DBAL\ParameterType::STRING;
        }
        if (count($input->groupIds) > 0) {
            $whereClauses[] = 'id IN(' . implode(',', $input->groupIds) . ')';
        }
        $perPage = $input->perPage;
        $page    = $input->page;
        $groups  = $this->groupRepository->findListPage($whereClauses, $input->order, $perPage, $perPage * $page, $listParams, $listTypes);
        return ['paging' => new PwgNamedStruct(['page' => $input->page, 'per_page' => $input->perPage, 'count' => count($groups)]), 'groups' => new PwgNamedArray($groups, 'group')];
    }
}
