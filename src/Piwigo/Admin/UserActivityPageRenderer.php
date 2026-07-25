<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

/**
 * Ported from admin/user_activity.php (page slug "user_activity") -- a
 * flat, read-only page. Confirmed via direct read: no write logic at all,
 * aside from the ?type=download_logs CSV-export branch, which streams a
 * response directly via header()/fputcsv()/exit() and never reaches the
 * template-render path below it -- same exit()-before-render shape already
 * used elsewhere in this project.
 */
final class UserActivityPageRenderer
{
    public function render(UrlServiceInterface $urlService): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        new \Piwigo\Validation\InputValidator()
            ->validate('photo', $_GET, false, ValidationPattern::ID);
        new \Piwigo\Validation\InputValidator()
            ->validate('album', $_GET, false, ValidationPattern::ID);
        new \Piwigo\Validation\InputValidator()
            ->validate('group', $_GET, false, ValidationPattern::ID);

        CoreTabs::setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('users');
        $tabsheet->select('user_activity');
        $tabsheet->assign();

        // \Piwigo\Config\CurrentConfig::userFields() maps generic field names to table-specific column
        // names; narrow once here and reuse below.
        /** @var array<string, string> $user_fields */
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();

        $conn = DbConnection::build();
        $activity_service = new ActivityService(new ActivityRepository($conn));

        if (isset($_GET['type']) && $_GET['type'] === 'download_logs') {
            $output_lines = [];

            $rows = $activity_service->getUserObjectLogWithUsernames($user_fields['username'], $user_fields['id']);
            array_push($output_lines, ['User', 'ID_User', 'Object', 'Object_ID', 'Action', 'Date', 'Hour', 'IP_Address', 'Details']);
            foreach ($rows as $row) {
                $details = str_replace(['`groups`', '`rank`'], ['groups', 'rank'], (string) $row->details);

                [$date, $hour] = explode(' ', $row->occuredOn);

                $output_lines[] = [
                    'username' => $row->username,
                    'user_id' => (string) $row->performedBy,
                    'object' => $row->object,
                    'object_id' => (string) $row->objectId,
                    'action' => $row->action,
                    'date' => $date,
                    'hour' => $hour,
                    'ip_address' => (string) $row->ipAddress,
                    'details' => $details,
                ];
            }

            header('Content-type: application/csv');
            header('Content-Disposition: attachment; filename=' . date('YmdGis') . 'piwigo_activity_log.csv');
            header('Content-Transfer-Encoding: UTF-8');

            $f = fopen('php://output', 'w');
            // the php://output stream wrapper always opens successfully
            assert($f !== false);
            foreach ($output_lines as $line) {
                fputcsv($f, $line, ';', '"', '\\');
            }
            fclose($f);

            exit();
        }

        $template->set_filename('user_activity', 'user_activity.tpl');
        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Users'));

        $template->assign([
            'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                ->getToken(),
            'INHERIT' => \Piwigo\Config\CurrentConfig::inheritanceByDefault(),
            'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($urlService, ['users']),
        ]);

        $nb_lines_for_user = $activity_service->getCountByUser();

        if (count($nb_lines_for_user) > 0) {
            $query = '
  SELECT
      ' . $user_fields['id'] . ' AS id,
      ' . $user_fields['username'] . ' AS username
    FROM ' . Tables::users() . '
    WHERE ' . $user_fields['id'] . ' IN (' . implode(',', array_keys($nb_lines_for_user)) . ');';
            $username_of = array_column($conn->fetchAllAssociative($query), 'username', 'id');
        } else {
            // no activity lines at all: skip the lookup query rather than
            // re-running the stale $query from above (previously left in place
            // from the "COUNT(*) as counter" query, whose rows have neither an
            // 'id' nor a 'username' column).
            $username_of = [];
        }

        $filterable_users = [];

        foreach ($nb_lines_for_user as $id => $nb_line) {
            array_push(
                $filterable_users,
                [
                    'id' => $id,
                    'username' => $username_of[$id] ?? 'user#' . $id,
                    'nb_lines' => $nb_line,
                ]
            );
        }
        $template->assign('ulist', $filterable_users);

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::users() . '
;';

        $row = $conn->fetchNumeric($query);
        $nb_users = is_array($row) ? $row[0] : 0;
        $template->assign('nb_users', $nb_users);

        $min_date = $activity_service->getMinOccuredOn();
        $max_date = $activity_service->getMaxOccuredOn();

        $template->assign(
            'ACTIVITY_DATES',
            [
                'min' => ($min_date === null || $min_date === '') ? '' : substr($min_date, 0, 10),
                'max' => ($max_date === null || $max_date === '') ? '' : substr($max_date, 0, 10),
            ]
        );

        $additional_filt_type = false;
        $additional_filt_name = null;
        $additional_filt_value = null;

        $additional_filters = [
            'photo' => Tables::images(),
            'album' => Tables::categories(),
            'group' => Tables::groups(),
        ];

        foreach ($additional_filters as $filter_key => $filter_table) {
            if (isset($_GET[$filter_key])) {
                $filter_value = $_GET[$filter_key];
                if (! is_string($filter_value)) {
                    \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                        ->fatalError('[Hacking attempt] the input parameter "' . $filter_key . '" is not valid');
                }

                $query = '
SELECT
    name
  FROM ' . $filter_table . '
  WHERE id = ' . $filter_value . '
;';
                $rows = $conn->fetchAllAssociative($query);

                if (count($rows) === 0) {
                    \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                        ->fatalError($filter_key . ' #' . $filter_value . ' does not exist');
                }

                $additional_filt_type = $filter_key;
                $additional_filt_name = $rows[0]['name'];
                $additional_filt_value = $filter_value;

                break;
            }
        }

        $template->assign('ADDITIONAL_FILT', [
            'type' => $additional_filt_type,
            'name' => $additional_filt_name,
            'value' => $additional_filt_value,
        ]);

        $actions = $activity_service->getActionCounts($additional_filt_type !== false ? $additional_filt_type : null);
        foreach ($actions as &$action) {
            $action['value'] = $action['object'] . '/' . $action['action'];
        }

        $template->assign('ACTIONS', $actions);

        $template->assign_var_from_handle('ADMIN_CONTENT', 'user_activity');
    }
}
