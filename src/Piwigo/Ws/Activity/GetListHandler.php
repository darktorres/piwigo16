<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Activity;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityListCriteria;
use Piwigo\Activity\ActivityService;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.activity.getList` -- admin only. Returns lines of users activity.
 *
 * @since 12
 */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private ActivityService $activityService,
        private UserService $userService,
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * result_lines' rows are genuinely heterogeneous (activity.details is
     * an entity-agnostic per-action payload, same rationale as
     * Admin\Maintenance\ActivityLogEntryFormatter's own $details); 'params'
     * echoes $params back for the WS client, same by-design shape.
     *
     * @param array<mixed> $params
     * @return WsErrorResponse|array{result_lines: array<int, array<string, mixed>>, page_offset: int, end_page: bool, params: array<mixed>}
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|array
    {
        $input = GetListParams::fromArray($params);

        foreach ([
            'date_min' => $input->dateMin,
            'date_max' => $input->dateMax,
        ] as $datefield => $datefield_value) {
            if (! in_array($datefield_value, [null, ''], true) and ! DateHelper::isValidMysqlDatetime($datefield_value)) {
                return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid ' . $datefield);
            }
        }

        $output_lines = [];
        $current_key = '';
        $page_size = 100; // We will fetch X lines in database =/= lines displayed due to line concatenation
        $page_offset = $input->offset;
        $nb_rows_to_fetch = 10000;

        $user_ids = [];

        $line_id = 0;

        // $min/$max are only read below when the same date_min/date_max
        // condition that sets them here is true again.
        $min = null;
        $max = null;
        $date_min_raw = $input->dateMin;
        $date_max_raw = $input->dateMax;
        if (! in_array($date_min_raw, [null, ''], true)) {
            // is_valid_sql_datetime() above already validated date_min; a
            // valid Y-m-d[ H:i:s] string always parses
            $min_date = date_create($date_min_raw);
            assert($min_date !== false);
            $min = date_format($min_date, 'Y-m-d H:i:s');

            // date_max may be empty/unvalidated/absent here — date_create()
            // only returns false on a genuinely malformed string, never on ''
            // (which means "now"), so a missing date_max is coalesced to ''
            $max_date = date_create($date_max_raw ?? '');
            assert($max_date !== false);
            $max = date_format($max_date, 'Y-m-d 23:59:59');
        }

        if (! in_array($date_max_raw, [null, ''], true)) {
            $max_date = date_create($date_max_raw);
            assert($max_date !== false);
            $max = date_format($max_date, 'Y-m-d 23:59:59');
        }

        // uid/id/admin-ids/action/object/date_min/date_max collapse into
        // an ActivityListCriteria, translated into bound conditions inside
        // ActivityRepository itself (see that class's own findPaginated()
        // docblock).
        $connections_mode = $this->currentConfig->activityDisplayConnections;
        $admin_ids = [];
        if ($connections_mode === 'admins_only') {
            $admin_ids = new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig)
                ->findAdminIds();
        }

        $criteria = new ActivityListCriteria(
            // uid/id are WsParamType::ID (optional, null default) --
            // Server::checkType() deliberately skips type coercion for an
            // empty-string value on an OPTIONAL param (matches legacy
            // ws_core.inc.php's own PwgServer::checkType() byte-for-byte),
            // so a present-but-empty 'uid'/'id' arrives here as the raw
            // string '', not null/int. UserId::from()/ActivityListCriteria's
            // own $objectId are both strictly typed (no string), so is_int()
            // -- not a null/'' exclusion list -- both narrows for PHPStan and
            // excludes that empty string, same is_string() shape as
            // action/object below.
            performedBy: is_int($input->uid) ? UserId::from($input->uid) : null,
            action: $input->action,
            object: $input->object,
            minDate: ! in_array($date_min_raw, [null, ''], true) ? SqlDateTime::from($min) : null,
            maxDate: ! in_array($date_max_raw, [null, ''], true) ? SqlDateTime::from($max) : null,
            objectId: (is_int($input->id) and $input->id !== 0) ? $input->id : null,
            connectionsMode: $connections_mode,
            adminIds: $admin_ids,
        );

        $more_rows_available = true;

        while (count($output_lines) < $page_size and $more_rows_available) {
            $rows = $this->activityService->getPaginated($criteria, $nb_rows_to_fetch, $page_offset);

            if (count($rows) < $nb_rows_to_fetch) {
                $more_rows_available = false;
            }

            foreach ($rows as $row) {
                if (count($output_lines) < $page_size) {
                    $page_offset++;

                    // ActivityRepository::findPaginated() now returns
                    // PaginatedActivityRow -- every field below reads its
                    // typed property directly, no per-field narrowing
                    // needed (the DTO already guarantees each real type).
                    $row_session_idx = $row->sessionIdx;
                    $row_object = $row->object;
                    $row_action = $row->action;
                    $row_object_id = (string) $row->objectId;
                    $row_ip_address = $row->ipAddress?->value;
                    $row_performed_by = $row->performedBy !== null ? (string) $row->performedBy : null;
                    $row_details = $row->details ?? [];
                    $row_occured_on = $row->occuredOn->value;

                    $line_key = $row_session_idx . '~' . $row_object . '~' . $row_action . '~'; // idx~photo~add

                    if ($line_key === $current_key) {
                        // I increment the counter of the previous line
                        $last_idx = count($output_lines) - 1;
                        $prev_counter = $output_lines[$last_idx]['counter'];
                        $output_lines[$last_idx]['counter'] = $prev_counter + 1;

                        // $output_lines elements are always built with the
                        // full literal shape below (id/object/object_id/...)
                        // -- PHPStan loses that precision once the array is
                        // both grown (`$output_lines[] = [...]`) and mutated
                        // by dynamic index in the same loop, widening every
                        // field to optional. Verified safe: $last_idx only
                        // reaches here after at least one element exists.
                        // An explicit @var on the assignment doesn't help
                        // here either -- unlike the msizes case above, this
                        // diagnostic flags the offset access itself, not the
                        // assigned variable's type, so a @var on
                        // has nothing to override.
                        // @phpstan-ignore offsetAccess.notFound
                        $prev_object_ids = $output_lines[$last_idx]['object_id'];
                        $prev_object_ids[] = $row_object_id;
                        $output_lines[$last_idx]['object_id'] = $prev_object_ids;
                    } else {
                        $details = $row_details;
                        $detailsType = null;

                        if ($row->userAgent !== null) {
                            $details['agent'] = $row->userAgent;
                        }

                        if (isset($details['method'])) {
                            $detailsType = 'method';
                        }

                        if (isset($details['script'])) {
                            $detailsType = 'script';
                        }

                        [$date, $hour] = explode(' ', $row_occured_on);
                        // New line
                        $output_lines[] = [
                            'id' => $line_id,
                            'object' => $row_object,
                            'object_id' => [$row_object_id],
                            'action' => $row_action,
                            'ip_address' => $row_ip_address,
                            'date' => DateHelper::formatDate($date),
                            'hour' => $hour,
                            'user_id' => $row_performed_by,
                            'detailsType' => $detailsType,
                            'details' => $details,
                            'counter' => 1,
                        ];

                        if ($row_performed_by !== null) {
                            $user_ids[$row_performed_by] = 1;
                        }
                        if ($row_object === 'user') {
                            $user_ids[$row_object_id] = 1;
                        }

                        $current_key = $line_key;
                        $line_id++;
                    }
                } else {
                    $more_rows_available = true;
                    break;
                }
            }
        }

        $username_of = [];
        if (count($user_ids) > 0) {
            $username_of = $this->userService->getUsernamesByIds(array_map(strval(...), array_keys($user_ids)));
        }

        foreach ($output_lines as $idx => $output_line) {
            if (($output_line['object'] ?? null) === 'user') {
                foreach ($output_line['object_id'] as $user_id) {
                    // Inside this loop, PHPStan can't prove 'details' still
                    // exists on every iteration (the loop itself
                    // conditionally rewrites $output_lines[$idx]['details']
                    // below) -- the ?? [] fallback is genuinely needed here,
                    // unlike the read just after this loop.
                    $details = $output_lines[$idx]['details'] ?? [];

                    $users = $details['users'] ?? [];
                    if (! is_array($users)) {
                        $users = [];
                    }

                    $users[] = $username_of[$user_id] ?? 'user#' . $user_id;
                    $details['users'] = $users;
                    $output_lines[$idx]['details'] = $details;
                }

                $details = $output_lines[$idx]['details'];
                $details['users_string'] = implode(', ', array_filter($details['users'], is_string(...)));
                $output_lines[$idx]['details'] = $details;
            }

            $user_id_val = $output_lines[$idx]['user_id'] ?? null;
            $user_id_key = is_string($user_id_val) ? $user_id_val : '';
            $output_lines[$idx]['username'] = 'user#' . $user_id_key;
            if (isset($username_of[$user_id_key])) {
                $output_lines[$idx]['username'] = $username_of[$user_id_key];
            }
        }

        return [
            'result_lines' => $output_lines,
            'page_offset' => $page_offset,
            'end_page' => ! $more_rows_available,
            'params' => $params,
        ];
    }
}
