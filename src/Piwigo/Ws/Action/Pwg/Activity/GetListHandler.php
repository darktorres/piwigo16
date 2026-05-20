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
        $input = GetListParams::fromArray($params);
        if ($input->dateMin !== null && !StringUtil::isValidMysqlDatetime($input->dateMin)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid date_min');
        }
        if ($input->dateMax !== null && !StringUtil::isValidMysqlDatetime($input->dateMax)) {
            return new PwgError(WsError::InvalidParam->value, 'Invalid date_max');
        }
        $outputLines   = [];
        $currentKey    = '';
        $pageSize      = 100;
        $pageOffset    = $input->offset;
        $nbRowsToFetch = 10000;
        $userIds       = [];
        $lineId        = 0;
        $min           = '';
        $max           = '';
        if ($input->dateMin !== null) {
            $dmin = date_create($input->dateMin);
            $dmax = $input->dateMax !== null ? date_create($input->dateMax) : false;
            $min  = $dmin !== false ? date_format($dmin, 'Y-m-d H:i:s') : '';
            $max  = $dmax !== false ? date_format($dmax, 'Y-m-d 23:59:59') : '';
        }
        if ($input->dateMax !== null) {
            $dmax2 = date_create($input->dateMax);
            $max   = $dmax2 !== false ? date_format($dmax2, 'Y-m-d 23:59:59') : '';
        }
        $performedBy = $input->uid;
        $actionVal   = $input->action;
        $objectVal   = $input->object;
        $dateMinSet  = $input->dateMin !== null;
        $dateMaxSet  = $input->dateMax !== null;
        $objectId    = $input->id;
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
                    $lineKey = ($row->sessionIdx ?? '') . '~' . $row->object . '~' . $row->action . '~';
                    if ($lineKey === $currentKey) {
                        $outputLines[count($outputLines) - 1]['counter']++;
                        $outputLines[count($outputLines) - 1]['object_id'][] = $row->objectId;
                    } else {
                        $sanitized      = strtr($row->details ?? '', ['`groups`' => 'groups', '`rank`' => 'rank']);
                        $detailsDecoded = json_decode($sanitized, associative: true);
                        $details        = is_array($detailsDecoded) ? $detailsDecoded : [];
                        if ($row->userAgent !== null) {
                            $details['agent'] = $row->userAgent;
                        }
                        $detailsType = '';
                        if (isset($details['method'])) {
                            $detailsType = 'method';
                        }
                        if (isset($details['script'])) {
                            $detailsType = 'script';
                        }
                        [$date, $hour] = explode(' ', $row->occuredOn);
                        $outputLines[] = ['id' => $lineId, 'object' => $row->object, 'object_id' => [$row->objectId], 'action' => $row->action, 'ip_address' => $row->ipAddress, 'date' => $this->dateService->formatDate($date), 'hour' => $hour, 'user_id' => $row->performedBy, 'detailsType' => $detailsType, 'details' => $details, 'counter' => 1];
                        if ($row->performedBy !== null) {
                            $userIds[(string) $row->performedBy] = 1;
                        }
                        if ('user' === $row->object && $row->objectId !== null) {
                            $userIds[$row->objectId] = 1;
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
