<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
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
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CoreTabs $coreTabs, \Piwigo\Template\CurrentTemplate $currentTemplate, \Piwigo\Config\CurrentConfig $currentConfig, \Piwigo\Activity\ActivityService $activityService, \Piwigo\Users\UserService $userService, \Piwigo\Image\ImageService $imageService, \Piwigo\Category\CategoryService $categoryService, \Piwigo\Group\GroupService $groupService, \Piwigo\Core\HtmlRenderingInterface $htmlRenderer, \Piwigo\Validation\InputValidator $inputValidator): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        $userActivityRequest = Request\UserActivityRequest::fromGlobals($inputValidator);

        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('users');
        $tabsheet->select('user_activity');
        $tabsheet->assign($currentTemplate);

        $activity_service = $activityService;

        if ($userActivityRequest->isDownloadLogs) {
            $output_lines = [];

            $rows = $activity_service->getUserObjectLogWithUsernames();
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
        $template->assign('ADMIN_PAGE_TITLE', $lang->t('Users'));

        $template->assign([
            'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                ->getToken(),
            'INHERIT' => $currentConfig->inheritanceByDefault(),
            'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($urlService, ['users']),
        ]);

        $nb_lines_for_user = $activity_service->getCountByUser();

        if (count($nb_lines_for_user) > 0) {
            $username_of = $userService->getUsernamesByIds(array_map(strval(...), array_keys($nb_lines_for_user)));
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

        $nb_users = $userService->getTotalUserCount();
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

        $additional_filter_keys = ['photo', 'album', 'group'];

        foreach ($additional_filter_keys as $filter_key) {
            if ($userActivityRequest->hasFilter($filter_key)) {
                $filter_value = $userActivityRequest->filterValue($filter_key);
                if (! is_string($filter_value)) {
                    $htmlRenderer
                        ->fatalError('[Hacking attempt] the input parameter "' . $filter_key . '" is not valid');
                }

                $filter_id = (int) $filter_value;
                $name = match ($filter_key) {
                    'photo' => $imageService->getImageRow($filter_id)['name'] ?? null,
                    'album' => $categoryService->getNamesByIds([$filter_id])[$filter_id]['name'] ?? null,
                    'group' => $groupService->getName(\Piwigo\Common\ValueObject\GroupId::from($filter_id)),
                };

                if ($name === null) {
                    $htmlRenderer
                        ->fatalError($filter_key . ' #' . $filter_value . ' does not exist');
                }

                $additional_filt_type = $filter_key;
                $additional_filt_name = $name;
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
