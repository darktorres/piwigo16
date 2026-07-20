<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Template\Template;

/**
 * Ported from admin/cat_perm.php (the "permissions" tab of the "album"
 * page slug, dispatched by AlbumSubController).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65,
 * unconditional, before $_GET['page']/['section'] are even validated), so
 * the original cat_perm.php's own (redundant) check_status() call is
 * dropped here -- same precedent as PhotosAddSubController.
 */
final class CatPermPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
    ) {}

    public function render(): void
    {
        /**
         * @var string
         */
        global $admin_album_base_url;
        /**
         * @var array<string, string|null>
         */
        global $category;
        // Phase 2 global-residual sweep: $page is a local scratch array
        // for this method's own body only (no longer `global $page;`),
        // same shape as Section\SectionPopulator::populate()'s own
        // equivalent fix (Track A5.2e).
        /** @var array<string, mixed> $page */
        $page = [];
        $template = \Piwigo\Template\CurrentTemplate::get();
        $conn = DbConnection::build();

        // +-------------------------------------------------------------------+
        // |                       variable initialization                     |
        // +-------------------------------------------------------------------+

        $page['cat'] = (int) $category['id'];

        // +-------------------------------------------------------------------+
        // |                           form submission                         |
        // +-------------------------------------------------------------------+

        if (! empty($_POST)) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new HtmlService(), $this->redirectService);

            // the status <select> always submits this field; fall back to an empty
            // string (never matches 'public'/'private') on malformed input
            $post_status = isset($_POST['status']) && is_string($_POST['status']) ? $_POST['status'] : '';
            $current_status = is_string($category['status']) ? $category['status'] : '';
            $apply_on_sub = isset($_POST['apply_on_sub']);

            $post_groups = [];
            if (isset($_POST['groups']) && is_array($_POST['groups'])) {
                foreach ($_POST['groups'] as $raw_group_id) {
                    if (is_numeric($raw_group_id)) {
                        $post_groups[] = (int) $raw_group_id;
                    }
                }
            }

            $post_users = [];
            if (isset($_POST['users']) && is_array($_POST['users'])) {
                foreach ($_POST['users'] as $raw_user_id) {
                    if (is_numeric($raw_user_id)) {
                        $post_users[] = (int) $raw_user_id;
                    }
                }
            }

            new CategoryAdminService(
                new CategoryService(
                    new CategoryRepository($conn),
                    new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn))
                )
            )->setCategoryPermissions($page['cat'], $current_status, $post_status, $apply_on_sub, $post_groups, $post_users);
            $category['status'] = $post_status;

            $template->assign(
                [
                    'save_success' => l10n('Album updated successfully'),
                ]
            );
        }

        // +-------------------------------------------------------------------+
        // |                       template initialization                     |
        // +-------------------------------------------------------------------+

        $template->set_filename('cat_perm', 'cat_perm.tpl');

        $template->assign(
            [
                'CATEGORIES_NAV' => new HtmlService()
                    ->getCatDisplayNameFromId(
                        $page['cat'],
                        'admin.php?page=album-'
                    ),
                'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=cat_perm',
                'F_ACTION' => $admin_album_base_url . '-permissions',
                'private' => ($category['status'] === 'private'),
            ]
        );

        // +-------------------------------------------------------------------+
        // |                          form construction                        |
        // +-------------------------------------------------------------------+

        // groups denied are the groups not granted. So we need to find all groups
        // minus groups granted to find groups denied.

        $groups = [];

        $query = '
SELECT id, name
  FROM `' . Tables::groups() . '`
  ORDER BY name ASC
;';
        $groups = array_column($conn->fetchAllAssociative($query), 'name', 'id');
        $template->assign('groups', $groups);

        // groups granted to access the category
        $query = '
SELECT group_id
  FROM ' . Tables::groupAccess() . '
  WHERE cat_id = ' . $page['cat'] . '
;';
        // array_from_query()'s own return type is declared array<int|string, mixed>;
        // narrow to the real int ids (DB values are string|null per this driver,
        // group_id is a NOT NULL numeric column)
        $group_granted_ids = [];
        foreach (array_column($conn->fetchAllAssociative($query), 'group_id') as $raw_group_id) {
            if (is_numeric($raw_group_id)) {
                $group_granted_ids[] = (int) $raw_group_id;
            }
        }
        $template->assign('groups_selected', $group_granted_ids);

        // users...
        $users = [];

        // \Piwigo\Config\Config::userFields() maps generic field names to table-specific column
        // names (see include/config_default.inc.php); every value is a plain
        // string.
        $user_fields = \Piwigo\Config\Config::userFields();
        $user_field_id = $user_fields['id'];
        $user_field_username = $user_fields['username'];

        $query = '
SELECT ' . $user_field_id . ' AS id,
       ' . $user_field_username . ' AS username
  FROM ' . Tables::users() . '
;';
        $users = array_column($conn->fetchAllAssociative($query), 'username', 'id');
        $template->assign('users', $users);

        $query = '
SELECT user_id
  FROM ' . Tables::userAccess() . '
  WHERE cat_id = ' . $page['cat'] . '
;';
        // array_from_query()'s own return type is declared array<int|string, mixed>;
        // narrow to the real int ids (DB values are string|null per this driver,
        // user_id is a NOT NULL numeric column)
        $user_granted_direct_ids = [];
        foreach (array_column($conn->fetchAllAssociative($query), 'user_id') as $raw_user_id) {
            if (is_numeric($raw_user_id)) {
                $user_granted_direct_ids[] = (int) $raw_user_id;
            }
        }
        $template->assign('users_selected', $user_granted_direct_ids);

        $user_granted_indirect_ids = [];
        if (count($group_granted_ids) > 0) {
            $granted_groups = [];

            $query = '
SELECT user_id, group_id
  FROM ' . Tables::userGroup() . '
  WHERE group_id IN (' . implode(',', $group_granted_ids) . ')
';
            foreach ($conn->fetchAllAssociative($query) as $row) {
                // group_id/user_id are NOT NULL numeric columns; DBAL can hand
                // back a native int for either (mysqli always gave a numeric
                // string), so accept both before using group_id as an array key
                // and collecting user_id.
                $row_group_id = $row['group_id'];
                $row_user_id = $row['user_id'];
                if ((! is_int($row_group_id) && ! is_string($row_group_id)) || (! is_int($row_user_id) && ! is_string($row_user_id))) {
                    continue;
                }
                $row_group_id = (int) $row_group_id;
                $row_user_id = (int) $row_user_id;
                if (! isset($granted_groups[$row_group_id])) {
                    $granted_groups[$row_group_id] = [];
                }
                $granted_groups[$row_group_id][] = $row_user_id;
            }

            $user_granted_by_group_ids = [];

            foreach ($granted_groups as $group_users) {
                $user_granted_by_group_ids = array_merge($user_granted_by_group_ids, $group_users);
            }

            $user_granted_by_group_ids = array_unique($user_granted_by_group_ids);

            $user_granted_indirect_ids = array_diff(
                $user_granted_by_group_ids,
                $user_granted_direct_ids
            );

            $template->assign('nb_users_granted_indirect', count($user_granted_indirect_ids));

            foreach ($granted_groups as $group_id => $group_users) {
                $group_usernames = [];
                foreach ($group_users as $user_id) {
                    // simple_hash_from_query()'s own return type is declared
                    // array<int|string, mixed>; narrow to the real username string
                    if (in_array($user_id, $user_granted_indirect_ids) && isset($users[$user_id]) && is_string($users[$user_id])) {
                        $group_usernames[] = $users[$user_id];
                    }
                }

                $template->append(
                    'user_granted_indirect_groups',
                    [
                        'group_name' => $groups[$group_id],
                        'group_users' => implode(', ', $group_usernames),
                    ]
                );
            }
        }

        // +-------------------------------------------------------------------+
        // |                           sending html code                       |
        // +-------------------------------------------------------------------+
        $template->assign([
            'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                ->getToken(),
            'INHERIT' => \Piwigo\Config\Config::inheritanceByDefault(),
            'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys(['groups', 'users']),
        ]);

        $template->assign_var_from_handle('ADMIN_CONTENT', 'cat_perm');
    }
}
