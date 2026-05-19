<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Activity;

use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Tables;
use Piwigo\Users\UserRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;

/** `pwg.activity.getList` (registered as the legacy alias) — paginated activity stream. */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private DateService $dateService,
        private UserAdminService $userAdminService,
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        foreach (['date_min', 'date_max'] as $datefield) {
            if (!empty($params[$datefield]) && !StringUtil::isValidMysqlDatetime(is_scalar($params[$datefield]) ? (string) $params[$datefield] : '')) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid ' . $datefield);
            }
        }
        $outputLines   = [];
        $currentKey    = '';
        $pageSize      = 100;
        $pageOffset    = is_numeric($params['offset']) ? (int) $params['offset'] : 0;
        $nbRowsToFetch = 10000;
        $userIds       = [];
        $lineId        = 0;
        $min           = '';
        $max           = '';
        if (!empty($params['date_min'])) {
            $dateMinStr = is_string($params['date_min']) ? $params['date_min'] : '';
            $dateMaxStr = isset($params['date_max']) && is_string($params['date_max']) ? $params['date_max'] : '';
            $dmin       = date_create($dateMinStr);
            $dmax       = $dateMaxStr !== '' ? date_create($dateMaxStr) : false;
            $min        = $dmin !== false ? date_format($dmin, 'Y-m-d H:i:s') : '';
            $max        = $dmax !== false ? date_format($dmax, 'Y-m-d 23:59:59') : '';
        }
        if (!empty($params['date_max'])) {
            $dateMaxStr2 = is_string($params['date_max']) ? $params['date_max'] : '';
            $dmax2       = date_create($dateMaxStr2);
            $max         = $dmax2 !== false ? date_format($dmax2, 'Y-m-d 23:59:59') : '';
        }
        $performedBy = isset($params['uid']) && is_numeric($params['uid']) ? (int) $params['uid'] : null;
        $actionVal   = isset($params['action']) && is_string($params['action']) ? $params['action'] : null;
        $objectVal   = isset($params['object']) && is_string($params['object']) ? $params['object'] : null;
        $dateMinVal  = $params['date_min'] ?? null;
        $dateMinSet  = $dateMinVal !== null && $dateMinVal !== '' && $dateMinVal !== false && $dateMinVal !== 0;
        $dateMaxVal  = $params['date_max'] ?? null;
        $dateMaxSet  = $dateMaxVal !== null && $dateMaxVal !== '' && $dateMaxVal !== false && $dateMaxVal !== 0;
        $objectId    = !empty($params['id']) && is_numeric($params['id']) ? (int) $params['id'] : null;
        $connections = Config::activityDisplayConnections();
        $adminIds    = $connections === 'admins_only' ? array_values($this->userAdminService->getAdmins()) : [];

        $moreRowsAvailable = true;
        while (count($outputLines) < $pageSize && $moreRowsAvailable) {
            $rows = $this->activityRepository->findActivityPage(
                $performedBy,
                $actionVal,
                $objectVal,
                $dateMinSet ? $min : null,
                $dateMaxSet ? $max : null,
                $objectId,
                $connections,
                $adminIds,
                $nbRowsToFetch,
                $pageOffset,
            );
            if (count($rows) < $nbRowsToFetch) {
                $moreRowsAvailable = false;
            }
            foreach ($rows as $row) {
                if (count($outputLines) < $pageSize) {
                    $pageOffset++;
                    $rowSessionIdx = is_string($row['session_idx'] ?? null) ? $row['session_idx'] : '';
                    $rowObject     = is_string($row['object'] ?? null) ? $row['object'] : '';
                    $rowAction     = is_string($row['action'] ?? null) ? $row['action'] : '';
                    $lineKey       = $rowSessionIdx . '~' . $rowObject . '~' . $rowAction . '~';
                    if ($lineKey === $currentKey) {
                        $outputLines[count($outputLines) - 1]['counter']++;
                        $outputLines[count($outputLines) - 1]['object_id'][] = $row['object_id'];
                    } else {
                        $rowDetailsStr  = is_string($row['details'] ?? null) ? $row['details'] : '';
                        $sanitized      = strtr($rowDetailsStr, ['`groups`' => 'groups', '`rank`' => 'rank']);
                        $detailsDecoded = json_decode($sanitized, associative: true);
                        $details        = is_array($detailsDecoded) ? $detailsDecoded : [];
                        if (isset($row['user_agent'])) {
                            $details['agent'] = $row['user_agent'];
                        }
                        $detailsType = '';
                        if (isset($details['method'])) {
                            $detailsType = 'method';
                        }
                        if (isset($details['script'])) {
                            $detailsType = 'script';
                        }
                        [$date, $hour]  = explode(' ', is_string($row['occured_on'] ?? null) ? $row['occured_on'] : '');
                        $rowPerformedBy = $row['performed_by'];
                        $outputLines[]  = ['id' => $lineId, 'object' => $rowObject, 'object_id' => [$row['object_id']], 'action' => $rowAction, 'ip_address' => $row['ip_address'], 'date' => $this->dateService->formatDate($date), 'hour' => $hour, 'user_id' => $rowPerformedBy, 'detailsType' => $detailsType, 'details' => $details, 'counter' => 1];
                        $userIdKey      = is_scalar($rowPerformedBy) ? (string) $rowPerformedBy : '';
                        if ($userIdKey !== '') {
                            $userIds[$userIdKey] = 1;
                        }
                        if ('user' === $rowObject) {
                            $objId    = $row['object_id'];
                            $objIdKey = is_scalar($objId) ? (string) $objId : '';
                            if ($objIdKey !== '') {
                                $userIds[$objIdKey] = 1;
                            }
                        }
                        $currentKey = $lineKey;
                        $lineId++;
                    }
                } else {
                    $moreRowsAvailable = true;
                    break;
                }
            }
        }
        $usernameOf = [];
        if (count($userIds) > 0) {
            $userFields = Config::userFields();
            $usernameOf = $this->userRepository->findUsernamesByIds(
                $userFields['id'],
                $userFields['username'],
                Tables::users(),
                array_map(intval(...), array_keys($userIds)),
            );
        }
        foreach ($outputLines as $idx => $outputLine) {
            if ('user' === ($outputLine['object'] ?? '')) {
                // PHPStan sees `object_id` as always defined; the
                // defensive ?? [] handles legacy output lines built
                // before B11 normalized the shape. Kept until the
                // output-line shape is fully typed.
                /** @phpstan-ignore-next-line nullCoalesce.offset */
                $objIds = $outputLine['object_id'] ?? [];
                foreach ($objIds as $uid) {
                    $uidKey = is_scalar($uid) ? (string) $uid : '';
                    $detRaw = $outputLines[$idx]['details'] ?? null;
                    /** @var array<string, mixed> $detArr */
                    $detArr = is_array($detRaw) ? $detRaw : [];
                    /** @var list<mixed> $detUsers */
                    $detUsersArr                  = $detArr['users'] ?? null;
                    $detUsers                     = is_array($detUsersArr) ? $detUsersArr : [];
                    $detUsers[]                   = $usernameOf[$uidKey] ?? ('user#' . $uidKey);
                    $detArr['users']              = $detUsers;
                    $outputLines[$idx]['details'] = $detArr;
                }
                // Same row-shape disagreement as the `object_id` access above.
                $detRaw2 = $outputLines[$idx]['details'] ?? null; // @phpstan-ignore nullCoalesce.offset
                /** @var array<string, mixed> $detArr2 */
                $detArr2 = is_array($detRaw2) ? $detRaw2 : [];
                if (isset($detArr2['users'])) {
                    $usersArr                     = is_array($detArr2['users']) ? $detArr2['users'] : [];
                    $detArr2['users_string']      = implode(', ', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $usersArr));
                    $outputLines[$idx]['details'] = $detArr2;
                }
            }
            $lineUserId    = $outputLines[$idx]['user_id'] ?? null;
            $lineUserIdStr = is_scalar($lineUserId) ? (string) $lineUserId : '';
            if ($lineUserId === null) {
                $outputLines[$idx]['username'] = 'System';
            } else {
                $outputLines[$idx]['username'] = $usernameOf[$lineUserIdStr] ?? ('user#' . $lineUserIdStr);
            }
        }
        return ['result_lines' => $outputLines, 'page_offset' => $pageOffset, 'end_page' => !$moreRowsAvailable, 'params' => $params];
    }
}
