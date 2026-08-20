<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityService;
use Piwigo\Activity\Projection\ActionCount;
use Piwigo\Admin\Projection\UserActivityView;
use Piwigo\Admin\Request\UserActivityRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Group\GroupService;
use Piwigo\Image\ImageService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/user_activity.php (page slug "user_activity") -- a
 * flat, read-only page. No write logic at all, aside from the
 * ?type=download_logs CSV-export branch, which streams a
 * response directly via header()/fputcsv()/exit() and never reaches the
 * template-render path below it -- same exit()-before-render shape already
 * used elsewhere in this project.
 */
final class UserActivityPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CoreTabs $coreTabs, CurrentTemplate $currentTemplate, ActivityService $activityService, UserService $userService, ImageService $imageService, CategoryService $categoryService, GroupService $groupService, HtmlRenderingInterface $htmlRenderer, InputValidator $inputValidator, EventDispatcher $eventDispatcher, Renderer $renderer): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        $userActivityRequest = UserActivityRequest::fromGlobals($inputValidator);

        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->setId('users');
        $tabsheet->select('user_activity', $eventDispatcher);
        $tabsheet->assign($currentTemplate, $renderer);

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

        $nb_lines_for_user = $activity_service->getCountByUser();

        if (count($nb_lines_for_user) > 0) {
            $username_of = $userService->getUsernamesByIds(array_map(strval(...), array_keys($nb_lines_for_user)));
        } else {
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
        $nb_users = $userService->getTotalUserCount();

        $min_date = $activity_service->getMinOccuredOn();
        $max_date = $activity_service->getMaxOccuredOn();

        $activity_dates = [
            'min' => ($min_date === null || $min_date === '') ? '' : substr($min_date, 0, 10),
            'max' => ($max_date === null || $max_date === '') ? '' : substr($max_date, 0, 10),
        ];

        $additional_filt_type = false;
        $additional_filt_name = null;
        $additional_filt_value = null;

        $additional_filter_keys = ['photo', 'album', 'group'];

        foreach ($additional_filter_keys as $filter_key) {
            if ($userActivityRequest->hasFilter($filter_key)) {
                $filter_value = $userActivityRequest->filterValue($filter_key);
                if (! is_string($filter_value)) {
                    $htmlRenderer
                        ->fatalError('Invalid request parameter "' . $filter_key . '"');
                }

                $filter_id = (int) $filter_value;
                $name = match ($filter_key) {
                    'photo' => $imageService->getImageRow($filter_id)['name'] ?? null,
                    'album' => $categoryService->getNamesByIds([$filter_id])[$filter_id]['name'] ?? null,
                    'group' => $groupService->getName(GroupId::from($filter_id)),
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

        $actions = array_map(
            static fn (ActionCount $action): array => [
                ...$action->toArray(),
                'value' => $action->object . '/' . $action->action,
            ],
            $activity_service->getActionCounts($additional_filt_type !== false ? $additional_filt_type : null)
        );

        $adminContent = $renderer->render(new UserActivityView(
            cacheKeys: AdminUiHelper::getAdminClientCacheKeys($urlService, ['users']),
            ulist: $filterable_users,
            nbUsers: $nb_users,
            activityDates: $activity_dates,
            additionalFiltType: $additional_filt_type,
            additionalFiltName: $additional_filt_name,
            additionalFiltValue: $additional_filt_value,
            actions: $actions,
        ));

        $template->assignContext(new AdminContentPageContext(
            adminContent: $adminContent,
            adminPageTitle: $lang->t('Users'),
        ));
    }
}
