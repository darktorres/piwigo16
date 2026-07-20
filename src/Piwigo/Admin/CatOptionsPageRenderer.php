<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;

/**
 * Ported from admin/cat_options.php (page slug "cat_options") -- a flat
 * page, pure delegate. The page's own bulk comments/visible/status/
 * representative toggling already calls
 * Piwigo\Admin\Category\CategoryAdminService::setCategoryOption()
 * (consolidating 8 switch-case branches into one parameterized method,
 * P21).
 */
final class CatOptionsPageRenderer
{
    private static function activityService(Connection $conn): ActivityService
    {
        return new ActivityService(new ActivityRepository($conn));
    }

    private static function categoryService(Connection $conn): CategoryService
    {
        return new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn))
        );
    }

    public function render(): void
    {
        $conn = DbConnection::build();
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        if ($_POST !== []) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new HtmlService());
            new \Piwigo\Validation\InputValidator()
                ->validate('cat_true', $_POST, true, ValidationPattern::ID);
            new \Piwigo\Validation\InputValidator()
                ->validate('cat_false', $_POST, true, ValidationPattern::ID);
            new \Piwigo\Validation\InputValidator()
                ->validate('section', $_GET, false, '/^[a-z0-9_-]+$/i');
        }

        if (isset($_POST['falsify'])
            and isset($_POST['cat_true'])
            and is_array($_POST['cat_true'])
            and count($_POST['cat_true']) > 0) {
            $cat_true = [];
            foreach ($_POST['cat_true'] as $raw_cat_id) {
                if (is_numeric($raw_cat_id)) {
                    $cat_true[] = (int) $raw_cat_id;
                }
            }

            $section_param = $_GET['section'] ?? '';
            new CategoryAdminService(self::categoryService($conn))
                ->setCategoryOption($cat_true, is_string($section_param) ? $section_param : '', false, self::activityService($conn));
        } elseif (isset($_POST['trueify'])
                 and isset($_POST['cat_false'])
                 and is_array($_POST['cat_false'])
                 and count($_POST['cat_false']) > 0) {
            $cat_false = [];
            foreach ($_POST['cat_false'] as $raw_cat_id) {
                if (is_numeric($raw_cat_id)) {
                    $cat_false[] = (int) $raw_cat_id;
                }
            }

            $section_param = $_GET['section'] ?? '';
            new CategoryAdminService(self::categoryService($conn))
                ->setCategoryOption($cat_false, is_string($section_param) ? $section_param : '', true, self::activityService($conn));
        }

        $template->set_filenames(
            [
                'cat_options' => 'cat_options.tpl',
                'double_select' => 'double_select.tpl',
            ]
        );

        $section = $_GET['section'] ?? 'status';
        if (! is_string($section) or ! in_array($section, ['comments', 'visible', 'status', 'representative'], true)) {
            $section = 'status';
        }
        $base_url = PHPWG_ROOT_PATH . 'admin.php?page=cat_options&amp;section=';

        $template->assign(
            [
                'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=cat_options',
                'F_ACTION' => $base_url . $section,
            ]
        );

        $tabsheet = new tabsheet();
        $tabsheet->set_id('cat_options');
        $tabsheet->select($section);
        $tabsheet->assign();

        // for each section, categories in the multiselect field can be :
        //
        // - true : commentable for comment section
        // - false : un-commentable for comment section
        // - NA : (not applicable) for virtual categories
        //
        // for true and false status, we associates an array of category ids,
        // function display_select_categories will use the given CSS class for each
        // option
        [$query_true, $query_false, $l_section, $l_true, $l_false] = match ($section) {
            'comments' => [
                '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . '
  WHERE commentable = \'true\'
;',
                '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . '
  WHERE commentable = \'false\'
;',
                l10n('Authorize users to add comments on selected albums'),
                l10n('Authorized'),
                l10n('Forbidden'),
            ],
            'visible' => [
                '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . '
  WHERE visible = \'true\'
;',
                '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . '
  WHERE visible = \'false\'
;',
                l10n('Lock albums'),
                l10n('Unlocked'),
                l10n('Locked'),
            ],
            'status' => [
                '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . '
  WHERE status = \'public\'
;',
                '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . '
  WHERE status = \'private\'
;',
                l10n('Manage authorizations for selected albums'),
                l10n('Public'),
                l10n('Private'),
            ],
            // 'representative' is the only value that can still reach here: $section
            // is already restricted to comments/visible/status/representative by
            // the in_array() guard above.
            default => [
                '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . '
  WHERE representative_picture_id IS NOT NULL
;',
                '
SELECT DISTINCT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id=category_id
  WHERE representative_picture_id IS NULL
;',
                l10n('Representative'),
                l10n('singly represented'),
                l10n('randomly represented'),
            ],
        };
        $template->assign(
            [
                'L_SECTION' => $l_section,
                'L_CAT_OPTIONS_TRUE' => $l_true,
                'L_CAT_OPTIONS_FALSE' => $l_false,
            ]
        );
        $categoryService = self::categoryService($conn);
        $categoryService->displaySelectCatWrapper($query_true, [], 'category_option_true', new HtmlService(), $template);
        $categoryService->displaySelectCatWrapper($query_false, [], 'category_option_false', new HtmlService(), $template);
        $template->assign('PWG_TOKEN', new \Piwigo\Csrf\CsrfService()->getToken());
        $template->assign('ADMIN_PAGE_TITLE', l10n('Properties of abums'));

        $template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
        $template->assign_var_from_handle('ADMIN_CONTENT', 'cat_options');
    }
}
