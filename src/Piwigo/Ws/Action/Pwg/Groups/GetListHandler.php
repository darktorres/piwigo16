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
        $orderStr = is_string($params['order'] ?? null) ? $params['order'] : '';
        if (!preg_match(ValidationPattern::ORDER, $orderStr)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid input parameter order');
        }
        $whereClauses = [];
        $listParams   = [];
        $listTypes    = [];
        if (!empty($params['name'])) {
            $whereClauses[] = 'LOWER(name) LIKE ?';
            $listParams[]   = is_string($params['name']) ? $params['name'] : '';
            $listTypes[]    = \Doctrine\DBAL\ParameterType::STRING;
        }
        if (!empty($params['group_id'])) {
            $groupIdArr     = is_array($params['group_id']) ? $params['group_id'] : [];
            $whereClauses[] = 'id IN(' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $groupIdArr)) . ')';
        }
        $perPage = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $page    = is_numeric($params['page']) ? (int) $params['page'] : 0;
        $groups  = $this->groupRepository->findListPage($whereClauses, $orderStr, $perPage, $perPage * $page, $listParams, $listTypes);
        return ['paging' => new PwgNamedStruct(['page' => $params['page'], 'per_page' => $params['per_page'], 'count' => count($groups)]), 'groups' => new PwgNamedArray($groups, 'group')];
    }
}
