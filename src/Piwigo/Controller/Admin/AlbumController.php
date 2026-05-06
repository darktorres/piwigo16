<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\AdminService;
use Piwigo\Admin\Album\AlbumsTabRenderer;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\NotFoundException;
use Piwigo\Exception\ValidationException;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;

final class AlbumController
{
    /** @var list<string> */
    public const array PAGES = [
        'album', 'albums', 'album_notification',
        'cat_list', 'cat_modify', 'cat_options', 'cat_perm',
        'element_set_ranks',
    ];

    /** @var array<string, mixed>|null */
    private ?array $albumCategory = null;
    private string $adminAlbumBaseUrl = '';

    public function handle(string $page): void
    {
        if ($page === 'album') {
            $this->album();
        } elseif ($page === 'albums') {
            $this->albums();
        } elseif ($page === 'album_notification') {
            $this->albumNotification();
        } elseif ($page === 'cat_list') {
            $this->catList();
        } elseif ($page === 'cat_modify') {
            $this->catModify();
        } elseif ($page === 'cat_options') {
            $this->catOptions();
        } elseif ($page === 'cat_perm') {
            $this->catPerm();
        } elseif ($page === 'element_set_ranks') {
            $this->elementSetRanks();
        }
    }

    // ── album ─────────────────────────────────────────────────────────────────

    private function album(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        check_input_parameter('cat_id', $_GET, false, PATTERN_ID);

        $cat_id_str = is_scalar($_GET['cat_id'] ?? null) ? (string) $_GET['cat_id'] : '';
        $this->adminAlbumBaseUrl = ServiceLocator::get(UrlGenerator::class)->admin('album-' . $cat_id_str);
        $this->albumCategory = ServiceLocator::get(CategoryRepository::class)
            ->findCategoryById(is_numeric($cat_id_str) ? (int) $cat_id_str : 0);

        if (!isset($this->albumCategory['id'])) {
            throw new NotFoundException('unknown album');
        }

        $page['tab'] = 'properties';
        if (isset($_GET['tab'])) {
            $page['tab'] = is_scalar($_GET['tab']) ? (string) $_GET['tab'] : 'properties';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('album');
        $tabsheet->select((string) $page['tab']);
        $tabsheet->assign();

        $category_name = trigger_change('render_category_name', $this->albumCategory['name'], 'get_cat_display_name_cache');
        $tpl->assign([
            'ADMIN_PAGE_TITLE'     => l10n('Edit album') . ' <strong>' . (is_scalar($category_name) ? (string) $category_name : '') . '</strong>',
            'ADMIN_PAGE_OBJECT_ID' => '#' . (is_scalar($this->albumCategory['id']) ? (string) $this->albumCategory['id'] : ''),
        ]);

        $tab = (string) $page['tab'];
        if ($tab === 'properties') {
            $this->catModify();
        } elseif ($tab === 'sort_order') {
            $this->elementSetRanks();
        } elseif ($tab === 'permissions') {
            $_GET['cat'] = $_GET['cat_id'];
            $this->catPerm();
        }
    }

    // ── albums ────────────────────────────────────────────────────────────────

    private function albums(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];
        $albums_counter = ServiceLocator::get(CategoryRepository::class)->countAll();

        check_input_parameter('parent_id', $_GET, false, PATTERN_ID);

        $page['tab'] = 'list';
        ServiceLocator::get(AlbumsTabRenderer::class)->render();

        $raw_open_cat = $_GET['parent_id'] ?? -1;
        $open_cat = is_scalar($raw_open_cat) ? (int) $raw_open_cat : -1;

        $sort_orders = [
            'name ASC', 'name DESC',
            'date_creation DESC', 'date_creation ASC',
            'date_available DESC', 'date_available ASC',
            'natural_order DESC', 'natural_order ASC',
        ];

        if (isset($_POST['simpleAutoOrder']) || isset($_POST['recursiveAutoOrder'])) {
            if (!in_array($_POST['order'], $sort_orders)) {
                throw new ValidationException('Invalid sort order');
            }
            check_input_parameter('id', $_POST, false, '/^-?\d+$/');

            $post_id_str = is_scalar($_POST['id']) ? (string) $_POST['id'] : '';
            $query = 'SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE id_uppercat ' . (($post_id_str === '-1') ? 'IS NULL' : '= ' . $post_id_str) . ';';
            $category_ids = array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id'));

            if (isset($_POST['recursiveAutoOrder'])) {
                $category_ids = get_subcat_ids($category_ids);
            }

            $categories = [];
            $sort = [];
            $ref_dates = [];

            [$order_by_field, $order_by_asc] = explode(' ', is_scalar($_POST['order']) ? (string) $_POST['order'] : '');

            $order_by_date = str_starts_with($order_by_field, 'date_');
            if ($order_by_date) {
                $ref_dates = $this->getCategoriesRefDate(
                    array_map(intval(...), $category_ids),
                    $order_by_field,
                    'ASC' == $order_by_asc ? 'min' : 'max'
                );
            }

            foreach (ServiceLocator::get(CategoryRepository::class)->findByIds(array_map(intval(...), $category_ids)) as $row) {
                $row['name'] = trigger_change('render_category_name', $row['name'], 'admin_cat_list');
                if ($order_by_date) {
                    $rowId  = is_scalar($row['id']) ? (string) $row['id'] : '';
                    $sort[] = $ref_dates[$rowId] ?? null;
                } else {
                    $sort[] = remove_accents(is_scalar($row['name']) ? (string) $row['name'] : '');
                }
                $categories[] = ['id' => $row['id'], 'id_uppercat' => $row['id_uppercat']];
            }

            array_multisort($sort, $order_by_field === 'natural_order' ? SORT_NATURAL : SORT_REGULAR, 'ASC' == $order_by_asc ? SORT_ASC : SORT_DESC, $categories);
            ServiceLocator::get(CategoryAdminService::class)->saveCategoriesOrder($categories);
            $open_cat = is_scalar($_POST['id']) ? (string) $_POST['id'] : '-1';
        }

        $tpl->assign('open_cat', $open_cat);
        $tpl->set_filename('albums', 'albums.tpl');
        $tpl->assign(['F_ACTION' => ServiceLocator::get(UrlGenerator::class)->admin('albums')]);
        $tpl->assign('delay_before_autoOpen', Config::albumMoveDelayBeforeAutoOpening());
        $tpl->assign('POS_PREF', Config::newcatDefaultPosition());

        $allAlbum = get_dbal_connection()->executeQuery('SELECT id,name,`rank`,status, visible, uppercats, lastmodified FROM ' . CATEGORIES_TABLE . ';')->fetchAllAssociative();

        $associatedTree = [];
        foreach ($allAlbum as $album) {
            $album['name'] = trigger_change('render_category_name', $album['name'], 'admin_cat_list');
            $album['lastmodified'] = time_since(is_string($album['lastmodified']) || is_int($album['lastmodified']) ? $album['lastmodified'] : null, 'year');
            $parents = explode(',', is_scalar($album['uppercats']) ? (string) $album['uppercats'] : '');
            $the_place = &$associatedTree[strval($parents[0])];
            for ($i = 1; $i < count($parents); $i++) {
                $the_place = &$the_place['children'][strval($parents[$i])];
            }
            $the_place['cat'] = $album;
        }

        $is_forbidden = array_fill_keys(explode(',', is_scalar($user['forbidden_categories'] ?? null) ? (string) $user['forbidden_categories'] : ''), 1);

        $nb_photos_in = array_column(get_dbal_connection()->executeQuery('SELECT category_id, COUNT(*) AS nb_photos FROM ' . IMAGE_CATEGORY_TABLE . ' GROUP BY category_id;')->fetchAllAssociative(), 'nb_photos', 'category_id');

        $all_categories = array_column(get_dbal_connection()->executeQuery('SELECT id, uppercats FROM ' . CATEGORIES_TABLE . ';')->fetchAllAssociative(), 'uppercats', 'id');
        $subcats_of = [];
        foreach ($all_categories as $id => $uppercats) {
            foreach (array_slice(explode(',', is_scalar($uppercats) ? (string) $uppercats : ''), 0, -1) as $uppercat_id) {
                $subcats_of[$uppercat_id][] = $id;
            }
        }

        $nb_sub_photos = [];
        foreach ($subcats_of as $cat_id => $subcat_ids) {
            $nb_photos = 0;
            foreach ($subcat_ids as $id) {
                $nb_photos += is_numeric($nb_photos_in[$id] ?? null) ? (int) $nb_photos_in[$id] : 0;
            }
            $nb_sub_photos[$cat_id] = $nb_photos;
        }

        $nb_albums           = count($allAlbum);
        $light_album_manager = ($albums_counter > Config::lightAlbumManagerThreshold()) ? 1 : 0;
        $album_tree          = $this->assocToOrderedTree($associatedTree, $nb_photos_in, $nb_sub_photos, $is_forbidden);

        $tpl->assign([
            'album_data'          => $album_tree,
            'PWG_TOKEN'           => get_pwg_token(),
            'nb_albums'           => $nb_albums,
            'ADMIN_PAGE_TITLE'    => l10n('Albums'),
            'light_album_manager' => $light_album_manager,
            'page_data_json'      => json_encode([
                'data'                          => $album_tree,
                'pwg_token'                     => get_pwg_token(),
                'openCat'                       => (int) $open_cat,
                'nb_albums'                     => $nb_albums,
                'light_album_manager'           => (bool) $light_album_manager,
                'delay_autoOpen'                => Config::albumMoveDelayBeforeAutoOpening(),
                'x_nb_subcats'                  => l10n('%d sub-albums'),
                'x_nb_images'                   => l10n('%d photos'),
                'x_nb_sub_photos'               => l10n('%d pictures in sub-albums'),
                'str_are_you_sure'              => l10n("The status of the album '%s' and its sub-albums will change to private. Are you sure?"),
                'str_yes_change_parent'         => l10n('Yes change parent anyway'),
                'str_no_change_parent'          => l10n("No, don't move this album here"),
                'str_albs_drag_drop'            => l10n('Drag and drop to reorder albums'),
                'delete_album_with_name'        => l10n('Delete album "%s".'),
                'delete_album_with_subs'        => l10n('Delete album "%s" and its %d sub-albums.'),
                'has_images_associated_outside' => l10n('delete album and all %d photos, even the %d associated to other albums'),
                'has_images_becomming_orphans'  => l10n('delete album and the %d orphan photos'),
                'rename_item'                   => l10n('Rename "%s"'),
                'str_add_album'                 => l10n('Add Album'),
                'str_edit_album'                => l10n('Edit album'),
                'str_add_photo'                 => l10n('Add Photos'),
                'str_visit_gallery'             => l10n('Visit Gallery'),
                'str_sort_order'                => l10n('Automatic sort order'),
                'str_delete_album'              => l10n('Delete album'),
                'str_root_order'                => l10n('Apply to root albums'),
                'str_sub_album_order'           => l10n('Apply to direct sub-albums'),
                'str_album_name_empty'          => l10n('Album name must not be empty'),
                'add_album_root_title'          => l10n('Create a new album at root'),
                'add_sub_album_of'              => l10n('Create a sub-album of "%s"'),
                'tiptip_locked_album'           => l10n('Locked album'),
                'str_albums_found'              => l10n('<b>%d</b> albums found'),
                'str_album_found'               => l10n('<b>1</b> album found'),
                'str_result_limit'              => l10n('<b>%d+</b> albums found, try to refine the search'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'albums');
    }

    // ── album_notification ────────────────────────────────────────────────────

    private function albumNotification(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        $category = $this->albumCategory;
        $admin_album_base_url = $this->adminAlbumBaseUrl;

        if ($category === null) {
            check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
            $cat_id_str = is_scalar($_GET['cat_id'] ?? null) ? (string) $_GET['cat_id'] : '';
            $admin_album_base_url = ServiceLocator::get(UrlGenerator::class)->admin('album-' . $cat_id_str);
            $category = ServiceLocator::get(CategoryRepository::class)
                ->findCategoryById(is_numeric($cat_id_str) ? (int) $cat_id_str : 0);
            if ($category === null) {
                throw new ValidationException('Invalid category');
            }
        }

        $GLOBALS['admin_album_base_url'] = $admin_album_base_url;
        $page['cat'] = $category['id'];

        if (isset($_POST['submitEmail'])) {
            check_pwg_token();
            set_make_full_url();

            $img = [];
            if (!empty($category['representative_picture_id'])) {
                $element = ServiceLocator::get(ImageRepository::class)
                    ->findById(is_numeric($category['representative_picture_id']) ? (int) $category['representative_picture_id'] : 0);
                if ($element !== null) {
                    $img = [
                        'link' => make_picture_url(['image_id' => $element['id'], 'image_file' => $element['file'], 'category' => $category]),
                        'src'  => DerivativeImage::url(IMG_THUMB, $element),
                    ];
                }
            }

            $args = ['subject' => l10n('[%s] Visit album %s', Config::galleryTitle(), trigger_change('render_category_name', $category['name'], 'admin_cat_list'))];
            $mailTpl = [
                'filename' => 'cat_group_info',
                'assign'   => [
                    'IMG'         => $img,
                    'CAT_NAME'    => trigger_change('render_category_name', $category['name'], 'admin_cat_list'),
                    'LINK'        => make_index_url(['category' => ['id' => $category['id'], 'name' => trigger_change('render_category_name', $category['name'], 'admin_cat_list'), 'permalink' => $category['permalink']]]),
                    'CPL_CONTENT' => empty($_POST['mail_content']) ? '' : stripslashes(is_scalar($_POST['mail_content']) ? (string) $_POST['mail_content'] : ''),
                ],
            ];

            if ('users' == $_POST['who'] && isset($_POST['users']) && is_array($_POST['users']) && count($_POST['users']) > 0) {
                check_input_parameter('users', $_POST, true, PATTERN_ID);
                $query = 'SELECT ui.user_id, ui.status, ui.language, u.' . Config::userFields()['email'] . ' AS email, u.' . Config::userFields()['username'] . ' AS username FROM ' . USER_INFOS_TABLE . ' AS ui JOIN ' . USERS_TABLE . ' AS u ON u.' . Config::userFields()['id'] . ' = ui.user_id WHERE ui.user_id IN (' . implode(',', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', (array) $_POST['users'])) . ');';
                $users     = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
                $usernames = [];
                foreach ($users as $u) {
                    $usernames[] = is_scalar($u['username']) ? (string) $u['username'] : '';
                    $authkey     = create_user_auth_key(is_numeric($u['user_id']) ? (int) $u['user_id'] : 0, is_string($u['status']) ? $u['status'] : null);
                    $user_tpl    = $mailTpl;
                    if ($authkey !== false) {
                        $user_tpl['assign']['LINK'] = add_url_params($mailTpl['assign']['LINK'], ['auth' => $authkey['auth_key']]);
                        if (isset($user_tpl['assign']['IMG']['link'])) {
                            $user_tpl['assign']['IMG']['link'] = add_url_params($user_tpl['assign']['IMG']['link'], ['auth' => $authkey['auth_key']]);
                        }
                    }
                    $user_args = $args;
                    if (isset($authkey['auth_key'])) {
                        $user_args['auth_key'] = $authkey['auth_key'];
                    }
                    switch_lang_to(is_scalar($u['language']) ? (string) $u['language'] : '');
                    pwg_mail(is_scalar($u['email']) ? (string) $u['email'] : '', $user_args, $user_tpl);
                    switch_lang_back();
                }
                $message  = l10n_dec('%d mail was sent.', '%d mails were sent.', count($users));
                $message .= ' (' . implode(', ', $usernames) . ')';
                $tpl->assign(['save_success' => $message]);
            } elseif ('group' == $_POST['who'] && !empty($_POST['group'])) {
                check_input_parameter('group', $_POST, false, PATTERN_ID);
                pwg_mail_group(is_numeric($_POST['group']) ? (int) $_POST['group'] : 0, $args, $mailTpl);
                $post_group_str = is_scalar($_POST['group']) ? (string) $_POST['group'] : '0';
                $group_name     = ServiceLocator::get(GroupRepository::class)->findNameById((int) $post_group_str);
                $tpl->assign(['save_success' => l10n('An information email was sent to group "%s"', $group_name)]);
            }

            unset_make_full_url();
        }

        $catIdScalar = is_scalar($page['cat']) ? (string) $page['cat'] : '';
        $tpl->set_filename('album_notification', 'album_notification.tpl');
        $tpl->assign([
            'CATEGORIES_NAV' => trim(get_cat_display_name_from_id($catIdScalar, ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-')),
            'F_ACTION'       => $admin_album_base_url . '-notification',
            'PWG_TOKEN'      => get_pwg_token(),
        ]);

        if (Config::authKeyDuration() > 0) {
            $tpl->assign('auth_key_duration', time_since(strtotime('now -' . Config::authKeyDuration() . ' second') ?: null, 'second', null, false));
        }

        $all_group_ids = array_column(get_dbal_connection()->executeQuery('SELECT id AS group_id FROM `' . GROUPS_TABLE . '`;')->fetchAllAssociative(), 'group_id');

        $group_ids = [];
        if (count($all_group_ids) == 0) {
            $tpl->assign('no_group_in_gallery', true);
        } else {
            if ('private' == $category['status']) {
                $tpl->assign('permission_url', $admin_album_base_url . '-permissions');
                $catIdInt  = is_numeric($category['id']) ? (int) $category['id'] : 0;
                $group_ids = array_column(get_dbal_connection()->executeQuery('SELECT group_id FROM ' . GROUP_ACCESS_TABLE . ' WHERE cat_id = ' . $catIdInt . ';')->fetchAllAssociative(), 'group_id');
            } else {
                $group_ids = $all_group_ids;
            }
            if (count($group_ids) > 0) {
                $tpl->assign('group_mail_options', array_column(get_dbal_connection()->executeQuery('SELECT id, name FROM `' . GROUPS_TABLE . '` WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $group_ids)) . ') ORDER BY name ASC;')->fetchAllAssociative(), 'name', 'id'));
            }
        }

        $all_user_ids = array_column(get_dbal_connection()->executeQuery('SELECT user_id FROM ' . USER_INFOS_TABLE . " WHERE status != 'guest';")->fetchAllAssociative(), 'user_id');

        if ('private' == $category['status']) {
            $catIdInt2 = is_numeric($category['id']) ? (int) $category['id'] : 0;
            $user_ids_access_indirect = [];
            if (count($group_ids) > 0) {
                $user_ids_access_indirect = array_column(get_dbal_connection()->executeQuery('SELECT user_id FROM ' . USER_GROUP_TABLE . ' WHERE group_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $group_ids)) . ');')->fetchAllAssociative(), 'user_id');
            }
            $user_ids_access_direct = array_column(get_dbal_connection()->executeQuery('SELECT user_id FROM ' . USER_ACCESS_TABLE . ' WHERE cat_id = ' . $catIdInt2 . ';')->fetchAllAssociative(), 'user_id');
            $user_ids_access = array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_merge($user_ids_access_direct, $user_ids_access_indirect)));
            $user_ids        = array_intersect($user_ids_access, array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $all_user_ids));
        } else {
            $user_ids = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $all_user_ids);
        }

        if (count($user_ids) > 0) {
            $tpl->assign('user_options', array_column(get_dbal_connection()->executeQuery('SELECT ' . Config::userFields()['id'] . ' AS id, ' . Config::userFields()['username'] . ' AS username FROM ' . USERS_TABLE . ' WHERE id IN (' . implode(',', $user_ids) . ');')->fetchAllAssociative(), 'username', 'id'));
        }

        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'album_notification');
    }

    // ── cat_list ──────────────────────────────────────────────────────────────

    private function catList(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        trigger_notify('loc_begin_cat_list');

        if (!empty($_POST) || isset($_GET['delete'])) {
            check_pwg_token();
        }

        $sort_orders = [
            'name ASC'            => l10n('Album name, A &rarr; Z'),
            'name DESC'           => l10n('Album name, Z &rarr; A'),
            'date_creation DESC'  => l10n('Date created, new &rarr; old') . ' ' . l10n('(determined from photos)'),
            'date_creation ASC'   => l10n('Date created, old &rarr; new') . ' ' . l10n('(determined from photos)'),
            'date_available DESC' => l10n('Date posted, new &rarr; old') . ' ' . l10n('(determined from photos)'),
            'date_available ASC'  => l10n('Date posted, old &rarr; new') . ' ' . l10n('(determined from photos)'),
        ];

        check_input_parameter('parent_id', $_GET, false, PATTERN_ID);

        $base_url   = ServiceLocator::get(UrlGenerator::class)->admin('cat_list');
        $navigation = '<a href="' . $base_url . '">' . l10n('Home') . '</a>';

        $page['tab'] = 'list';
        ServiceLocator::get(AlbumsTabRenderer::class)->render();

        if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
            $photo_deletion_mode = 'no_delete';
            if (isset($_GET['photo_deletion_mode'])) {
                $photo_deletion_mode = $_GET['photo_deletion_mode'];
            }
            ServiceLocator::get(CategoryAdminService::class)->deleteCategories([(int) $_GET['delete']], is_scalar($photo_deletion_mode) ? (string) $photo_deletion_mode : 'no_delete');
            $_SESSION['page_infos'] = [l10n('Virtual album deleted')];
            ServiceLocator::get(CategoryAdminService::class)->updateGlobalRank();
            ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
            $redirect_url = ServiceLocator::get(UrlGenerator::class)->admin('cat_list');
            if (isset($_GET['parent_id'])) {
                $redirect_url .= '&parent_id=' . (is_scalar($_GET['parent_id']) ? (string) $_GET['parent_id'] : '');
            }
            redirect($redirect_url);
        } elseif (isset($_POST['submitAdd'])) {
            $output_create = ServiceLocator::get(CategoryAdminService::class)->createVirtualCategory(
                is_scalar($_POST['virtual_name']) ? (string) $_POST['virtual_name'] : '',
                isset($_GET['parent_id']) ? (is_scalar($_GET['parent_id']) ? (string) $_GET['parent_id'] : null) : null
            );
            ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
            if (isset($output_create['error'])) {
                PageState::current()->addError(is_scalar($output_create['error']) ? (string) $output_create['error'] : '');
            } else {
                $edit_url = ServiceLocator::get(UrlGenerator::class)->admin('album-' . (is_scalar($output_create['id'] ?? '') ? (string) ($output_create['id'] ?? '') : ''));
                PageState::current()->addInfo((is_scalar($output_create['info'] ?? '') ? (string) ($output_create['info'] ?? '') : '') . ' <a class="icon-pencil" href="' . $edit_url . '">' . l10n('Edit album') . '</a>');
            }
        }

        if (isset($_GET['parent_id'])) {
            $navigation .= Config::levelSeparator();
            $raw_parent_id = $_GET['parent_id'];
            $navigation   .= get_cat_display_name_from_id(is_scalar($raw_parent_id) ? (int) $raw_parent_id : 0, $base_url . '&amp;parent_id=');
        }

        $tpl->set_filename('categories', 'cat_list.tpl');
        $form_action = ServiceLocator::get(UrlGenerator::class)->admin('cat_list');
        if (isset($_GET['parent_id'])) {
            $form_action .= '&amp;parent_id=' . (is_scalar($_GET['parent_id']) ? (string) $_GET['parent_id'] : '');
        }
        $sort_orders_checked = array_keys($sort_orders);

        $tpl->assign([
            'ADMIN_PAGE_TITLE'    => l10n('Album list management'),
            'CATEGORIES_NAV'      => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
            'F_ACTION'            => $form_action,
            'PWG_TOKEN'           => get_pwg_token(),
            'sort_orders'         => $sort_orders,
            'sort_order_checked'  => array_shift($sort_orders_checked),
        ]);

        $query = 'SELECT id, name, permalink, dir, `rank`, status FROM ' . CATEGORIES_TABLE;
        if (!isset($_GET['parent_id'])) {
            $query .= ' WHERE id_uppercat IS NULL';
        } else {
            $query .= ' WHERE id_uppercat = ' . (is_numeric($_GET['parent_id']) ? (int) $_GET['parent_id'] : 0);
        }
        $query .= ' ORDER BY `rank` ASC;';
        $categories = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'id');

        $nb_photos_in  = [];
        $nb_sub_photos = [];
        $subcats_of    = [];

        if (count($categories)) {
            $nb_photos_in    = array_column(get_dbal_connection()->executeQuery('SELECT category_id, COUNT(*) AS nb_photos FROM ' . IMAGE_CATEGORY_TABLE . ' GROUP BY category_id;')->fetchAllAssociative(), 'nb_photos', 'category_id');
            $all_categories  = array_column(get_dbal_connection()->executeQuery('SELECT id, uppercats FROM ' . CATEGORIES_TABLE . ';')->fetchAllAssociative(), 'uppercats', 'id');

            foreach ($all_categories as $id => $uppercats) {
                foreach (array_slice(explode(',', is_scalar($uppercats) ? (string) $uppercats : ''), 0, -1) as $uppercat_id) {
                    $subcats_of[$uppercat_id][] = $id;
                }
            }

            foreach ($subcats_of as $cat_id => $subcat_ids) {
                $nb_photos = 0;
                foreach ($subcat_ids as $id) {
                    $nb_photos += is_numeric($nb_photos_in[$id] ?? null) ? (int) $nb_photos_in[$id] : 0;
                }
                $nb_sub_photos[$cat_id] = $nb_photos;
            }
        }

        $tpl->assign('categories', []);
        $base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';

        if (isset($_GET['parent_id'])) {
            $tpl->assign('PARENT_EDIT', $base_url . 'album-' . (is_scalar($_GET['parent_id']) ? (string) $_GET['parent_id'] : ''));
        }

        foreach ($categories as $category) {
            $cat_list_url = $base_url . 'cat_list';
            $self_url     = $cat_list_url;
            if (isset($_GET['parent_id'])) {
                $self_url .= '&amp;parent_id=' . (is_scalar($_GET['parent_id']) ? (string) $_GET['parent_id'] : '');
            }

            $catIdStr = (string) (is_numeric($category['id']) ? (int) $category['id'] : 0);
            $tpl_cat  = [
                'NAME'             => trigger_change('render_category_name', $category['name'], 'admin_cat_list'),
                'NB_PHOTOS'        => $nb_photos_in[$catIdStr] ?? 0,
                'NB_SUB_PHOTOS'    => $nb_sub_photos[$catIdStr] ?? 0,
                'NB_SUB_ALBUMS'    => isset($subcats_of[$catIdStr]) ? count($subcats_of[$catIdStr]) : 0,
                'ID'               => $category['id'],
                'RANK'             => (is_numeric($category['rank'] ?? null) ? (int) $category['rank'] : 0) * 10,
                'U_JUMPTO'         => make_index_url(['category' => $category]),
                'U_CHILDREN'       => $cat_list_url . '&amp;parent_id=' . (is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''),
                'U_EDIT'           => $base_url . 'album-' . (is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''),
                'U_ADD_PHOTOS_ALBUM' => $base_url . 'photos_add&amp;album=' . (is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''),
                'U_MOVE'           => $base_url . 'albums#cat-' . (is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''),
                'IS_VIRTUAL'       => empty($category['dir']),
                'CAT_ADMIN_ACCESS' => ServiceLocator::get(UserAdminService::class)->catAdminAccess(is_numeric($category['id'] ?? null) ? (int) $category['id'] : 0),
            ];

            if (empty($category['dir'])) {
                $tpl_cat['U_DELETE']  = $self_url . '&amp;delete=' . (is_scalar($category['id']) ? (string) $category['id'] : '');
                $tpl_cat['U_DELETE'] .= '&amp;pwg_token=' . get_pwg_token();
            } elseif (Config::enableSynchronization()) {
                $tpl_cat['U_SYNC'] = $base_url . 'site_update&amp;site=1&amp;cat_id=' . (is_scalar($category['id']) ? (string) $category['id'] : '');
            }

            $tpl->append('categories', $tpl_cat);
        }

        trigger_notify('loc_end_cat_list');
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'categories');
    }

    // ── cat_modify ────────────────────────────────────────────────────────────

    private function catModify(): void
    {
        $tpl = TemplateRegistry::current();
        trigger_notify('loc_begin_cat_modify');

        if (!isset($_GET['cat_id']) || !is_numeric($_GET['cat_id'])) {
            trigger_error('missing cat_id param', E_USER_ERROR);
        }

        $category = $this->albumCategory;
        $admin_album_base_url = $this->adminAlbumBaseUrl;

        if ($category === null) {
            $cat_id_str           = (string) $_GET['cat_id'];
            $admin_album_base_url = ServiceLocator::get(UrlGenerator::class)->admin('album-' . $cat_id_str);
            $category             = ServiceLocator::get(CategoryRepository::class)->findCategoryById((int) $cat_id_str);
            if ($category === null) {
                throw new ValidationException('Invalid category');
            }
        }

        $GLOBALS['admin_album_base_url'] = $admin_album_base_url;
        foreach (['comment', 'dir', 'site_id', 'id_uppercat'] as $nullable) {
            if (!isset($category[$nullable])) {
                $category[$nullable] = '';
            }
        }

        // typed locals — $category is array<string, mixed>
        $catId        = is_scalar($category['id']) ? (string) $category['id'] : '0';
        $catIntId     = is_numeric($category['id']) ? (int) $category['id'] : 0;
        $catName      = is_scalar($category['name'] ?? null) ? (string) $category['name'] : '';
        $catComment   = is_scalar($category['comment']) ? (string) $category['comment'] : '';
        $catVisible   = is_string($category['visible'] ?? null) ? $category['visible'] : 'false';
        $catUppercats = is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '';
        $catUppercat  = is_scalar($category['id_uppercat']) ? (string) $category['id_uppercat'] : '';
        $catSiteId    = is_scalar($category['site_id']) ? (string) $category['site_id'] : '';
        $catLastmod   = is_string($category['lastmodified'] ?? null) || is_int($category['lastmodified'] ?? null) ? $category['lastmodified'] : null;
        $catRepPic    = is_scalar($category['representative_picture_id'] ?? null) ? (string) $category['representative_picture_id'] : '';
        $catComment_b = is_string($category['commentable'] ?? null) ? $category['commentable'] : 'false';

        $category['is_virtual'] = empty($category['dir']);
        $category['has_images'] = ServiceLocator::get(CategoryRepository::class)->hasCategoryImages($catIntId);
        $subcat_ids             = get_subcat_ids([$catId]);
        $category['nb_subcats'] = count($subcat_ids) - 1;

        $navigation = get_cat_display_name_cache($catUppercats, ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-');

        $uppercats_array = explode(',', $catUppercats);
        if (count($uppercats_array) > 1) {
            array_pop($uppercats_array);
            $parent_navigation = get_cat_display_name_cache(implode(',', $uppercats_array), ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-');
        } else {
            $parent_navigation = l10n('Root');
        }

        $tpl->set_filename('album_properties', 'cat_modify.tpl');
        $base_url     = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';
        $cat_list_url = $base_url . 'albums';
        $self_url     = $cat_list_url;
        if ($catUppercat !== '') {
            $self_url .= '&amp;parent_id=' . $catUppercat;
        }

        PageState::current()->addWarning(l10n('This album is currently locked, visible only to administrators.') . '<span class="icon-cone unlock-album">' . l10n('Unlock it') . '</span>');

        $tpl->assign([
            'CATEGORIES_NAV'        => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
            'CATEGORIES_PARENT_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $parent_navigation)),
            'PARENT_CAT_ID'         => $catUppercat !== '' ? $catUppercat : 0,
            'CAT_ID'                => $catId,
            'CAT_NAME'              => htmlspecialchars($catName),
            'CAT_COMMENT'           => htmlspecialchars($catComment),
            'IS_VISIBLE'            => BoolUtil::toString($catVisible),
            'CAT_ADMIN_ACCESS'      => ServiceLocator::get(UserAdminService::class)->catAdminAccess($catIntId),
            'U_DELETE'              => $base_url . 'albums',
            'U_JUMPTO'              => make_index_url(['category' => $category]),
            'U_ADD_PHOTOS_ALBUM'    => $base_url . 'photos_add&amp;album=' . $catId,
            'U_CHILDREN'            => $cat_list_url . '&amp;parent_id=' . $catId,
            'U_MOVE'                => $base_url . 'albums&amp;parent_id=' . $catId,
            'U_ACTIVITY'            => ServiceLocator::get(UrlGenerator::class)->admin('user_activity') . '&album=' . $catId,
        ]);

        if (Config::activateComments()) {
            $tpl->assign('CAT_COMMENTABLE', BoolUtil::toString($catComment_b));
        }

        $image_count = 0;
        $info_title  = '';
        if ($category['has_images']) {
            $tpl->assign('U_MANAGE_ELEMENTS', $base_url . 'batch_manager&amp;filter=album-' . $catId);
            [$image_count, $min_date, $max_date] = ServiceLocator::get(CategoryRepository::class)->findImageStats($catIntId);
            $min_date = (string) $min_date;
            $max_date = (string) $max_date;
            $info_title = ($min_date == $max_date)
                ? l10n('This album contains %d photos, added on %s.', $image_count, format_date($min_date))
                : l10n('This album contains %d photos, added between %s and %s.', $image_count, format_date($min_date), format_date($max_date));
        }
        $tpl->assign(['INFO_PHOTO' => l10n('%d photos', $image_count), 'INFO_TITLE' => $info_title]);

        $category['nb_images_recursive'] = count(array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT (image_id) FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id IN (' . implode(',', $subcat_ids) . ');')->fetchAllAssociative(), 'image_id'));

        $result = get_dbal_connection()->executeQuery('SELECT occured_on FROM `' . ACTIVITY_TABLE . '` WHERE object_id = ' . $catIntId . ' AND object = "album" AND action = "add"')->fetchAllAssociative();
        if (count($result) > 0) {
            $occurred_on = is_scalar($result[0]['occured_on']) ? (string) $result[0]['occured_on'] : '';
            $tpl->assign(['INFO_CREATION_SINCE' => time_since($occurred_on, 'day', null, true, true, true), 'INFO_CREATION' => format_date($occurred_on, ['day', 'month', 'year'])]);
        }

        $result = get_dbal_connection()->executeQuery('SELECT COUNT(*) FROM `' . CATEGORIES_TABLE . '` WHERE id_uppercat = ' . $catIntId)->fetchAllAssociative();
        $tpl->assign(['INFO_DIRECT_SUB' => l10n('%d sub-albums', $result[0]['COUNT(*)'])]);

        $tpl->assign([
            'INFO_ID'                  => l10n('Numeric identifier : %d', $catId),
            'INFO_LAST_MODIFIED_SINCE' => time_since($catLastmod, 'minute', null, true, true, true),
            'INFO_LAST_MODIFIED'       => format_date($catLastmod, ['day', 'month', 'year']),
            'INFO_IMAGES_RECURSIVE'    => l10n('%d including sub-albums', $category['nb_images_recursive']),
            'INFO_SUBCATS'             => l10n('%d in whole branch', $category['nb_subcats']),
            'NB_SUBCATS'               => $category['nb_subcats'],
        ]);

        $tpl->assign(['U_MANAGE_RANKS' => $base_url . 'element_set_ranks&amp;cat_id=' . $catId, 'CACHE_KEYS' => ServiceLocator::get(AdminService::class)->getAdminClientCacheKeys(['categories'])]);

        if (!$category['is_virtual']) {
            $category['cat_full_dir'] = $this->getCompleteDir((string) $_GET['cat_id']);
            $category_full_dir = preg_replace('/\/$/', '', (string) $category['cat_full_dir']);
            $tpl->assign(['CAT_FULL_DIR' => $category_full_dir]);
            $tpl->assign('CAT_DIR_NAME', basename((string) $category_full_dir));
            $tpl->assign('CAT_MIN_DIR', $this->getMinLocalDir((string) ($category_full_dir ?? '')));
            if (Config::enableSynchronization()) {
                $tpl->assign('U_SYNC', $base_url . 'site_update&amp;site=' . $catSiteId . '&amp;cat_id=' . $catId);
            }
        }

        if ($category['has_images'] || $catRepPic !== '') {
            $tpl_representant = [];
            if ($catRepPic !== '') {
                $tpl_representant['picture'] = ServiceLocator::get(ImageAdminService::class)->getCategoryRepresentantProperties($catRepPic, IMG_MEDIUM);
            }
            $tpl_representant['ALLOW_SET_RANDOM'] = (bool) $category['has_images'];
            if (($category['has_images'] && Config::allowRandomRepresentative()) || (!$category['has_images'] && $catRepPic !== '')) {
                $tpl_representant['ALLOW_DELETE'] = true;
            }
            $tpl->assign('representant', $tpl_representant);
        }

        if ($category['is_virtual']) {
            $tpl->assign('parent_category', $catUppercat === '' ? [] : [$catUppercat]);
        }

        $tpl->assign('PWG_TOKEN', get_pwg_token());
        $pwg_token     = get_pwg_token();
        $parent_cat_id = $catUppercat !== '' ? (int) $catUppercat : 0;
        $tpl->assign('page_data_json', json_encode([
            'album_id'                             => $catIntId,
            'album_name'                           => $catName,
            'default_parent_album'                 => $parent_cat_id,
            'is_visible'                           => BoolUtil::toString($catVisible),
            'nb_sub_albums'                        => $category['nb_subcats'],
            'parent_album'                         => $parent_cat_id,
            'related_categories_ids'               => [$catId, (string) $parent_cat_id],
            'u_delete'                             => $base_url . 'albums',
            'pwg_token'                            => $pwg_token,
            'str_cancel'                           => l10n('No, I have changed my mind'),
            'str_delete_album'                     => l10n('Delete album'),
            'str_delete_album_and_his_x_subalbums' => l10n('Delete album "%s" and its %d sub-albums.'),
            'str_just_now'                         => l10n('Just now'),
            'str_dont_delete_photos'               => l10n('delete only album, not photos'),
            'str_delete_orphans'                   => l10n('delete album and the %d orphan photos'),
            'str_delete_all_photos'                => l10n('delete album and all %d photos, even the %d associated to other albums'),
            'str_album_comment_allow'              => l10n('Comments allowed for sub-albums'),
            'str_album_comment_disallow'           => l10n('Comments disallowed for sub-albums'),
            'str_modal_ab'                         => l10n('New parent album'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        trigger_notify('loc_end_cat_modify');
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'album_properties');
    }

    // ── cat_options ───────────────────────────────────────────────────────────

    private function catOptions(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        if (!empty($_POST)) {
            check_pwg_token();
            check_input_parameter('cat_true', $_POST, true, PATTERN_ID);
            check_input_parameter('cat_false', $_POST, true, PATTERN_ID);
            check_input_parameter('section', $_GET, false, '/^[a-z0-9_-]+$/i');
        }

        if (isset($_POST['falsify']) && isset($_POST['cat_true']) && count(is_array($_POST['cat_true']) ? $_POST['cat_true'] : []) > 0) {
            /** @var int[] $cat_true */
            $cat_true        = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['cat_true']) ? $_POST['cat_true'] : []);
            $current_section = is_scalar($_GET['section'] ?? null) ? (string) $_GET['section'] : '';
            match ($current_section) {
                'comments'       => ServiceLocator::get(CategoryRepository::class)->setCommentable($cat_true, false),
                'visible'        => ServiceLocator::get(CategoryAdminService::class)->setCatVisible($cat_true, 'false'),
                'status'         => ServiceLocator::get(CategoryAdminService::class)->setCatStatus($cat_true, 'private'),
                'representative' => ServiceLocator::get(CategoryRepository::class)->clearRepresentatives($cat_true),
                default          => null,
            };
            pwg_activity('album', $cat_true, 'edit', ['section' => $current_section, 'action' => 'falsify']);
        } elseif (isset($_POST['trueify']) && isset($_POST['cat_false']) && count(is_array($_POST['cat_false']) ? $_POST['cat_false'] : []) > 0) {
            /** @var int[] $cat_false */
            $cat_false       = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['cat_false']) ? $_POST['cat_false'] : []);
            $current_section = is_scalar($_GET['section'] ?? null) ? (string) $_GET['section'] : '';
            match ($current_section) {
                'comments'       => ServiceLocator::get(CategoryRepository::class)->setCommentable($cat_false, true),
                'visible'        => ServiceLocator::get(CategoryAdminService::class)->setCatVisible($cat_false, 'true'),
                'status'         => ServiceLocator::get(CategoryAdminService::class)->setCatStatus($cat_false, 'public'),
                'representative' => ServiceLocator::get(CategoryAdminService::class)->setRandomRepresentant($cat_false),
                default          => null,
            };
            pwg_activity('album', $cat_false, 'edit', ['section' => $current_section, 'action' => 'trueify']);
        }

        $tpl->set_filenames(['cat_options' => 'cat_options.tpl', 'double_select' => 'double_select.tpl']);

        $get_section     = $_GET['section'] ?? null;
        $page['section'] = is_scalar($get_section) ? (string) $get_section : 'status';
        $base_url        = ServiceLocator::get(UrlGenerator::class)->admin('cat_options') . '&amp;section=';

        $tpl->assign(['U_HELP' => ServiceLocator::get(UrlGenerator::class)->adminPopupHelp('cat_options'), 'F_ACTION' => $base_url . $page['section']]);

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('cat_options');
        $tabsheet->select($page['section']);
        $tabsheet->assign();

        $query_true = $query_false = '';
        if ($page['section'] === 'comments') {
            $query_true  = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . " WHERE commentable = 'true';";
            $query_false = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . " WHERE commentable = 'false';";
            $tpl->assign(['L_SECTION' => l10n('Authorize users to add comments on selected albums'), 'L_CAT_OPTIONS_TRUE' => l10n('Authorized'), 'L_CAT_OPTIONS_FALSE' => l10n('Forbidden')]);
        } elseif ($page['section'] === 'visible') {
            $query_true  = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . " WHERE visible = 'true';";
            $query_false = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . " WHERE visible = 'false';";
            $tpl->assign(['L_SECTION' => l10n('Lock albums'), 'L_CAT_OPTIONS_TRUE' => l10n('Unlocked'), 'L_CAT_OPTIONS_FALSE' => l10n('Locked')]);
        } elseif ($page['section'] === 'status') {
            $query_true  = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . " WHERE status = 'public';";
            $query_false = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . " WHERE status = 'private';";
            $tpl->assign(['L_SECTION' => l10n('Manage authorizations for selected albums'), 'L_CAT_OPTIONS_TRUE' => l10n('Public'), 'L_CAT_OPTIONS_FALSE' => l10n('Private')]);
        } elseif ($page['section'] === 'representative') {
            $query_true  = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . ' WHERE representative_picture_id IS NOT NULL;';
            $query_false = 'SELECT DISTINCT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . ' INNER JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id=category_id WHERE representative_picture_id IS NULL;';
            $tpl->assign(['L_SECTION' => l10n('Representative'), 'L_CAT_OPTIONS_TRUE' => l10n('singly represented'), 'L_CAT_OPTIONS_FALSE' => l10n('randomly represented')]);
        }

        display_select_cat_wrapper($query_true, [], 'category_option_true');
        display_select_cat_wrapper($query_false, [], 'category_option_false');
        $tpl->assign('PWG_TOKEN', get_pwg_token());
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Properties of abums'));
        $tpl->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'cat_options');
    }

    // ── cat_perm ──────────────────────────────────────────────────────────────

    private function catPerm(): void
    {
        $tpl = TemplateRegistry::current();
        check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
        $cat_id = is_scalar($_GET['cat_id'] ?? null) ? (string) $_GET['cat_id'] : '';
        if (empty($cat_id)) {
            throw new ValidationException('No category selected');
        }

        $category = get_cat_info((int) $cat_id);
        if ($category === null) {
            throw new ValidationException('Invalid category');
        }
        $pageCat = is_numeric($category['id'] ?? null) ? (int) $category['id'] : 0;

        $GLOBALS['admin_album_base_url'] = $admin_album_base_url = $this->adminAlbumBaseUrl !== '' ? $this->adminAlbumBaseUrl : ServiceLocator::get(UrlGenerator::class)->admin('album-' . $cat_id);

        if (!empty($_POST)) {
            check_pwg_token();

            $post_status = is_scalar($_POST['status'] ?? null) ? (string) $_POST['status'] : '';
            if ($category['status'] != $post_status || ($category['status'] != 'public' && isset($_POST['apply_on_sub']))) {
                $cat_ids = [$pageCat];
                if (isset($_POST['apply_on_sub'])) {
                    $cat_ids = array_merge($cat_ids, get_subcat_ids([$pageCat]));
                }
                ServiceLocator::get(CategoryAdminService::class)->setCatStatus($cat_ids, $post_status);
                $category['status'] = $post_status;
            }

            if ('private' == $post_status) {
                $groups_granted     = array_column(get_dbal_connection()->executeQuery('SELECT group_id FROM ' . GROUP_ACCESS_TABLE . ' WHERE cat_id = ' . $pageCat . ';')->fetchAllAssociative(), 'group_id');
                if (!isset($_POST['groups'])) {
                    $_POST['groups'] = [];
                }
                /** @var int[] $post_groups */
                $post_groups        = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['groups']) ? $_POST['groups'] : []);
                /** @var int[] $groups_granted_int */
                $groups_granted_int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $groups_granted);

                $deny_groups = array_diff($groups_granted_int, $post_groups);
                if (count($deny_groups) > 0) {
                    ServiceLocator::get(PermissionRepository::class)->deleteGroupAccess(array_map(intval(...), $deny_groups), array_map(intval(...), get_subcat_ids([$pageCat])));
                }

                $grant_groups = $post_groups;
                if (count($grant_groups) > 0) {
                    $cat_ids = ServiceLocator::get(CategoryAdminService::class)->getUppercatIds([$pageCat]);
                    if (isset($_POST['apply_on_sub'])) {
                        $cat_ids = array_merge($cat_ids, get_subcat_ids([$pageCat]));
                    }
                    $private_cats = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', array_map(fn (int|string $v): string => (string) $v, $cat_ids)) . ") AND status = 'private';")->fetchAllAssociative(), 'id');
                    $inserts = [];
                    foreach ($private_cats as $cid) {
                        foreach ($grant_groups as $gid) {
                            $inserts[] = ['group_id' => $gid, 'cat_id' => $cid];
                        }
                    }
                    mass_inserts(GROUP_ACCESS_TABLE, ['group_id', 'cat_id'], $inserts, ['ignore' => true]);
                }

                $users_granted     = array_column(get_dbal_connection()->executeQuery('SELECT user_id FROM ' . USER_ACCESS_TABLE . ' WHERE cat_id = ' . $pageCat . ';')->fetchAllAssociative(), 'user_id');
                if (!isset($_POST['users'])) {
                    $_POST['users'] = [];
                }
                /** @var int[] $post_users */
                $post_users        = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['users']) ? $_POST['users'] : []);
                /** @var int[] $users_granted_int */
                $users_granted_int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $users_granted);

                $deny_users = array_diff($users_granted_int, $post_users);
                if (count($deny_users) > 0) {
                    ServiceLocator::get(PermissionRepository::class)->deleteUserAccess(array_map(intval(...), $deny_users), array_map(intval(...), get_subcat_ids([$pageCat])));
                }
                if (count($post_users) > 0) {
                    ServiceLocator::get(CategoryAdminService::class)->addPermissionOnCategory($pageCat, $post_users);
                }
            }

            $tpl->assign(['save_success' => l10n('Album updated successfully')]);
        }

        $tpl->set_filename('cat_perm', 'cat_perm.tpl');
        $tpl->assign([
            'CATEGORIES_NAV' => get_cat_display_name_from_id($pageCat, ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-'),
            'U_HELP'         => ServiceLocator::get(UrlGenerator::class)->adminPopupHelp('cat_perm'),
            'F_ACTION'       => $admin_album_base_url . '-permissions',
            'private'        => ('private' == $category['status']),
        ]);

        $groups          = array_column(get_dbal_connection()->executeQuery('SELECT id, name FROM `' . GROUPS_TABLE . '` ORDER BY name ASC;')->fetchAllAssociative(), 'name', 'id');
        $group_granted_ids = array_column(get_dbal_connection()->executeQuery('SELECT group_id FROM ' . GROUP_ACCESS_TABLE . ' WHERE cat_id = ' . $pageCat . ';')->fetchAllAssociative(), 'group_id');
        $users           = array_column(get_dbal_connection()->executeQuery('SELECT ' . Config::userFields()['id'] . ' AS id, ' . Config::userFields()['username'] . ' AS username FROM ' . USERS_TABLE . ';')->fetchAllAssociative(), 'username', 'id');
        $user_granted_direct_ids = array_column(get_dbal_connection()->executeQuery('SELECT user_id FROM ' . USER_ACCESS_TABLE . ' WHERE cat_id = ' . $pageCat . ';')->fetchAllAssociative(), 'user_id');

        $tpl->assign('groups', $groups);
        $tpl->assign('groups_selected', $group_granted_ids);
        $tpl->assign('users', $users);
        $tpl->assign('users_selected', $user_granted_direct_ids);

        $user_granted_indirect_ids = [];
        if (count($group_granted_ids) > 0) {
            $granted_groups = [];
            foreach (ServiceLocator::get(GroupRepository::class)->findUserGroupMembersByGroupIds(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $group_granted_ids)) as $row) {
                $row_group_id = is_scalar($row['group_id']) ? (string) $row['group_id'] : '';
                if (!isset($granted_groups[$row_group_id])) {
                    $granted_groups[$row_group_id] = [];
                }
                $granted_groups[$row_group_id][] = is_scalar($row['user_id']) ? (string) $row['user_id'] : '';
            }
            $user_granted_by_group_ids = [];
            foreach ($granted_groups as $group_users) {
                $user_granted_by_group_ids = array_merge($user_granted_by_group_ids, $group_users);
            }
            $user_granted_by_group_ids = array_unique($user_granted_by_group_ids);
            $user_granted_indirect_ids = array_diff($user_granted_by_group_ids, array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $user_granted_direct_ids));

            $tpl->assign('nb_users_granted_indirect', count($user_granted_indirect_ids));
            foreach ($granted_groups as $group_id => $group_users) {
                $group_usernames = [];
                foreach ($group_users as $user_id) {
                    if (in_array($user_id, $user_granted_indirect_ids)) {
                        $group_usernames[] = isset($users[$user_id]) ? (is_scalar($users[$user_id]) ? (string) $users[$user_id] : '') : '';
                    }
                }
                $tpl->append('user_granted_indirect_groups', ['group_name' => isset($groups[$group_id]) ? (is_scalar($groups[$group_id]) ? (string) $groups[$group_id] : '') : '', 'group_users' => implode(', ', $group_usernames)]);
            }
        }

        $cache_keys = ServiceLocator::get(AdminService::class)->getAdminClientCacheKeys(['groups', 'users']);
        $tpl->assign([
            'PWG_TOKEN'               => get_pwg_token(),
            'INHERIT'                 => Config::inheritanceByDefault(),
            'CACHE_KEYS'              => $cache_keys,
            'cat_perm_page_data_json' => json_encode(['CACHE_KEYS' => $cache_keys, 'ROOT_URL' => get_root_url(), 'str_create' => l10n('Create'), 'has_indirect_perms' => count($user_granted_indirect_ids) > 0]),
        ]);

        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'cat_perm');
    }

    // ── element_set_ranks ─────────────────────────────────────────────────────

    private function elementSetRanks(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        $sort_fields = [
            '' => '', 'file ASC' => l10n('File name, A &rarr; Z'), 'file DESC' => l10n('File name, Z &rarr; A'),
            'name ASC' => l10n('Photo title, A &rarr; Z'), 'name DESC' => l10n('Photo title, Z &rarr; A'),
            'date_creation DESC' => l10n('Date created, new &rarr; old'), 'date_creation ASC' => l10n('Date created, old &rarr; new'),
            'date_available DESC' => l10n('Date posted, new &rarr; old'), 'date_available ASC' => l10n('Date posted, old &rarr; new'),
            'rating_score DESC' => l10n('Rating score, high &rarr; low'), 'rating_score ASC' => l10n('Rating score, low &rarr; high'),
            'hit DESC' => l10n('Visits, high &rarr; low'), 'hit ASC' => l10n('Visits, low &rarr; high'),
            'id ASC' => l10n('Numeric identifier, 1 &rarr; 9'), 'id DESC' => l10n('Numeric identifier, 9 &rarr; 1'),
            'rank ASC' => l10n('Manual sort order'),
        ];

        if (!isset($_GET['cat_id']) || !is_numeric($_GET['cat_id'])) {
            trigger_error('missing cat_id param', E_USER_ERROR);
        }

        $page['category_id']  = $_GET['cat_id'];
        $image_order_choices  = ['default', 'rank', 'user_define'];
        $image_order_choice   = 'default';

        if (isset($_POST['submit'])) {
            if (isset($_POST['rank_of_image'])) {
                $rank_of_image = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['rank_of_image']) ? $_POST['rank_of_image'] : []);
                asort($rank_of_image, SORT_NUMERIC);
                ServiceLocator::get(CategoryAdminService::class)->saveImagesOrder((int) $page['category_id'], array_keys($rank_of_image));
            }

            if (!empty($_POST['image_order_choice']) && in_array($_POST['image_order_choice'], $image_order_choices)) {
                $image_order_choice = $_POST['image_order_choice'];
            }

            $message     = l10n('Album updated successfully');
            $image_order = null;
            if ($image_order_choice == 'user_define') {
                $image_order_post = is_array($_POST['image_order'] ?? null) ? $_POST['image_order'] : [];
                for ($i = 0; $i < 3; $i++) {
                    $image_order_val = is_string($image_order_post[$i] ?? null) ? $image_order_post[$i] : null;
                    if (!empty($image_order_val) && in_array($image_order_val, array_keys($sort_fields))) {
                        $image_order = (!empty($image_order) ? $image_order . ',' : '') . $image_order_val;
                    }
                }
            } elseif ($image_order_choice == 'rank') {
                $image_order = '`rank` ASC';
                $message     = l10n('Images manual order was saved');
            }

            $category_id_int = (int) $page['category_id'];
            $catRepo = ServiceLocator::get(CategoryRepository::class);
            $catRepo->updateImageOrder($category_id_int, $image_order ?? null);

            if (isset($_POST['image_order_subcats'])) {
                $cat_info = get_cat_info((string) $category_id_int);
                $catRepo->updateImageOrderForSubcats(is_scalar($cat_info['uppercats'] ?? null) ? (string) $cat_info['uppercats'] : '', $image_order ?? null);
            }

            $tpl->assign(['save_success' => $message]);
        }

        $tpl->set_filenames(['element_set_ranks' => 'element_set_ranks.tpl']);
        $base_url = ServiceLocator::get(UrlGenerator::class)->admin();
        $category = ServiceLocator::get(CategoryRepository::class)->findCategoryById((int) $page['category_id']);

        if ($category !== null && ($category['image_order'] == 'rank ASC' || $category['image_order'] == '`rank` ASC')) {
            $image_order_choice = 'rank';
        } elseif ($category !== null && $category['image_order'] != '') {
            $image_order_choice = 'user_define';
        }

        $navigation = get_cat_display_name_cache(is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '', ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-');
        $tpl->assign(['CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)), 'F_ACTION' => $base_url . get_query_string_diff([])]);

        $imgRows = ServiceLocator::get(ImageRepository::class)->findByCategoryIdOrdered((int) $page['category_id']);
        if (count($imgRows) > 0) {
            $current_rank     = 1;
            $derivativeParams = ImageStdParams::get_by_type(IMG_SQUARE);
            foreach ($imgRows as $row) {
                $derivative     = new DerivativeImage($derivativeParams, new SrcImage($row));
                $thumbnail_name = !empty($row['name']) ? $row['name'] : str_replace('_', ' ', get_filename_wo_extension(is_scalar($row['file']) ? (string) $row['file'] : ''));
                $current_rank++;
                $tpl->append('thumbnails', ['ID' => $row['id'], 'NAME' => $thumbnail_name, 'TN_SRC' => $derivative->get_url(), 'RANK' => $current_rank * 10, 'SIZE' => $derivative->get_size()]);
            }
        }

        $tpl->assign('image_order_options', $sort_fields);
        $image_order = explode(',', is_scalar($category['image_order'] ?? null) ? (string) $category['image_order'] : '');
        for ($i = 0; $i < 3; $i++) {
            $tpl->append('image_order', $image_order[$i] ?? '');
        }
        $tpl->assign('image_order_choice', $image_order_choice);
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'element_set_ranks');
    }

    // ── private helpers ───────────────────────────────────────────────────────

    /**
     * @param int[]|int|string $ids
     * @return array<mixed>
     */
    private function getCategoriesRefDate(array|int|string $ids, string $field = 'date_available', string $minmax = 'max'): array
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        $category_ids = get_subcat_ids($ids);

        $ref_dates = array_column(get_dbal_connection()->executeQuery('SELECT category_id, ' . $minmax . '(' . $field . ') as ref_date FROM ' . IMAGE_CATEGORY_TABLE . ' JOIN ' . IMAGES_TABLE . ' ON image_id = id WHERE category_id IN (' . implode(',', $category_ids) . ') GROUP BY category_id;')->fetchAllAssociative(), 'ref_date', 'category_id');

        $uppercats_of = array_column(get_dbal_connection()->executeQuery('SELECT id, uppercats FROM ' . CATEGORIES_TABLE . ' WHERE id IN (' . implode(',', $category_ids) . ');')->fetchAllAssociative(), 'uppercats', 'id');

        foreach (array_keys($uppercats_of) as $cat_id) {
            $subcat_ids = [];
            foreach ($uppercats_of as $id => $uppercats) {
                if (preg_match('/(^|,)' . $cat_id . '(,|$)/', is_scalar($uppercats) ? (string) $uppercats : '')) {
                    $subcat_ids[] = $id;
                }
            }
            $to_compare = [];
            foreach ($subcat_ids as $id) {
                if (isset($ref_dates[$id])) {
                    $to_compare[] = $ref_dates[$id];
                }
            }
            $ref_dates[$cat_id] = count($to_compare) > 0 ? ('max' == $minmax ? max($to_compare) : min($to_compare)) : null;
        }

        $return = [];
        foreach ($ids as $id) {
            $return[$id] = $ref_dates[$id] ?? null;
        }
        return $return;
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function cmpCat(array $a, array $b): int
    {
        return ($a['rank'] ?? 0) <=> ($b['rank'] ?? 0);
    }

    /**
     * @param array<mixed> $assocT
     * @param array<mixed> $nbPhotosIn
     * @param array<mixed> $nbSubPhotos
     * @param array<mixed> $isForbidden
     * @return array<mixed>
     */
    private function assocToOrderedTree(array $assocT, array $nbPhotosIn, array $nbSubPhotos, array $isForbidden): array
    {
        $orderedTree = [];
        foreach ($assocT as $cat) {
            if (!is_array($cat) || !is_array($cat['cat'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $catData */
            $catData     = $cat['cat'];
            $catId       = is_scalar($catData['id']) ? (string) $catData['id'] : '';
            $orderedCat  = [
                'rank'          => $catData['rank'],
                'name'          => $catData['name'],
                'status'        => $catData['status'],
                'id'            => $catData['id'],
                'visible'       => $catData['visible'],
                'uppercats'     => $catData['uppercats'],
                'nb_images'     => $nbPhotosIn[$catId] ?? 0,
                'last_updates'  => $catData['lastmodified'],
                'has_not_access' => isset($isForbidden[$catId]),
                'nb_sub_photos' => $nbSubPhotos[$catId] ?? 0,
            ];
            if (isset($cat['children'])) {
                $children = is_array($cat['children']) ? $cat['children'] : [];
                $orderedCat['nb_subcats'] = count($children);
                $orderedCat['children']   = $this->assocToOrderedTree($children, $nbPhotosIn, $nbSubPhotos, $isForbidden);
            }
            $orderedTree[] = $orderedCat;
        }
        usort($orderedTree, $this->cmpCat(...));
        return $orderedTree;
    }

    private function getCompleteDir(string $category_id): string
    {
        return $this->getSiteUrl($category_id) . $this->getLocalDir($category_id);
    }

    private function getLocalDir(string $category_id): string
    {
        /** @var array<string, mixed> $page */
        $page        = &$GLOBALS['page'];
        $local_dir   = '';
        $plainStructure = is_array($page['plain_structure'] ?? null) ? $page['plain_structure'] : [];
        $catEntry    = is_array($plainStructure[$category_id] ?? null) ? $plainStructure[$category_id] : [];

        if (isset($catEntry['uppercats']) && is_scalar($catEntry['uppercats'])) {
            $uppercats = (string) $catEntry['uppercats'];
        } else {
            $uppercats = ServiceLocator::get(CategoryRepository::class)->findUppercatsStringById((int) $category_id) ?? '';
        }

        $upper_array   = explode(',', $uppercats);
        $database_dirs = ServiceLocator::get(CategoryRepository::class)->findIdDirMap(array_map(intval(...), $upper_array));
        foreach ($upper_array as $id) {
            $local_dir .= $database_dirs[$id] . '/';
        }
        return $local_dir;
    }

    private function getSiteUrl(string $category_id): string
    {
        return ServiceLocator::get(CategoryRepository::class)->findGalleriesUrlByCategoryId((int) $category_id) ?? '';
    }

    private function getMinLocalDir(string $local_dir): string
    {
        $full_dir = explode('/', $local_dir);
        if (count($full_dir) <= 3) {
            return $local_dir;
        }
        return $full_dir[0] . '/' . $full_dir[1] . '/&hellip;/' . end($full_dir);
    }
}
