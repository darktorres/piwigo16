<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Album\AlbumsTabRenderer;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Exception\NotFoundException;
use Piwigo\Exception\ValidationException;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;

final class AlbumController
{
    /** @var list<string> */
    public const array PAGES = [
        'album', 'albums', 'album_notification',
        'cat_list', 'cat_modify', 'cat_options', 'cat_perm',
        'element_set_ranks',
    ];

    public function __construct(
        private readonly Connection $conn,
        private readonly AdminService $adminService,
        private readonly AlbumsTabRenderer $albumsTabRenderer,
        private readonly AuthService $authService,
        private readonly CategoryAdminService $categoryAdminService,
        private readonly CategoryRepository $categoryRepository,
        private readonly CategoryService $categoryService,
        private readonly DateService $dateService,
        private readonly GroupRepository $groupRepository,
        private readonly HtmlService $htmlService,
        private readonly ImageAdminService $imageAdminService,
        private readonly ImageRepository $imageRepository,
        private readonly MailService $mailService,
        private readonly PermissionRepository $permissionRepository,
        private readonly StringUtil $stringUtil,
        private readonly UrlGenerator $urlGenerator,
        private readonly UrlService $urlService,
        private readonly UserAdminService $userAdminService,
        private readonly ActivityLogger $activityLogger,
        private readonly CsrfService $csrfService,
        private readonly InputValidator $inputValidator,
        private readonly RedirectResponder $redirectResponder,
    ) {
    }

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

        $this->inputValidator->check('cat_id', $_GET, false, ValidationPattern::ID);

        $rawCatId = $_GET['cat_id'] ?? null;
        $cat_id_str = is_string($rawCatId) ? $rawCatId : '';
        $this->adminAlbumBaseUrl = $this->urlGenerator->admin('album-' . $cat_id_str);
        $this->albumCategory = $this->categoryRepository
            ->findCategoryById(is_numeric($cat_id_str) ? (int) $cat_id_str : 0);

        if (!isset($this->albumCategory['id'])) {
            throw new NotFoundException('unknown album');
        }

        $rawTab = $_GET['tab'] ?? null;
        $tab    = is_string($rawTab) ? $rawTab : 'properties';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('album');
        $tabsheet->select($tab);
        $tabsheet->assign();

        $category_name = EventDispatcher::dispatch('render_category_name', $this->albumCategory['name'], 'get_cat_display_name_cache');
        $tpl->assign([
            'ADMIN_PAGE_TITLE'     => new Html(Lang::t('Edit album') . ' <strong>' . htmlspecialchars(is_scalar($category_name) ? (string) $category_name : '') . '</strong>'),
            'ADMIN_PAGE_OBJECT_ID' => '#' . (is_scalar($this->albumCategory['id']) ? (string) $this->albumCategory['id'] : ''),
        ]);
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
        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;
        $albums_counter = $this->categoryRepository->countAll();

        $this->inputValidator->check('parent_id', $_GET, false, ValidationPattern::ID);

        $this->albumsTabRenderer->render('list');

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
            $this->inputValidator->check('id', $_POST, false, '/^-?\d+$/');

            $rawPostId = $_POST['id'] ?? null;
            $post_id_str = is_string($rawPostId) ? $rawPostId : '-1';
            $query = 'SELECT id FROM ' . Tables::categories() . ' WHERE id_uppercat ' . (($post_id_str === '-1') ? 'IS NULL' : '= ' . $post_id_str) . ';';
            $category_ids = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id'));

            if (isset($_POST['recursiveAutoOrder'])) {
                $category_ids = $this->categoryService->getSubcatIds($category_ids);
            }

            $categories = [];
            $sort = [];
            $ref_dates = [];

            [$order_by_field, $order_by_asc] = explode(' ', is_string($rawOrder = $_POST['order'] ?? null) ? $rawOrder : '');

            $order_by_date = str_starts_with($order_by_field, 'date_');
            if ($order_by_date) {
                $ref_dates = $this->getCategoriesRefDate(
                    array_map(intval(...), $category_ids),
                    $order_by_field,
                    'ASC' == $order_by_asc ? 'min' : 'max'
                );
            }

            foreach ($this->categoryRepository->findByIds(array_map(intval(...), $category_ids)) as $row) {
                $row['name'] = EventDispatcher::dispatch('render_category_name', $row['name'], 'admin_cat_list');
                if ($order_by_date) {
                    $rowIdRaw = $row['id'] ?? null;
                    $rowId    = is_string($rowIdRaw) ? $rowIdRaw : '';
                    $sort[] = $ref_dates[$rowId] ?? null;
                } else {
                    $sort[] = $this->stringUtil->removeAccents(is_string($row['name']) ? $row['name'] : '');
                }
                $categories[] = ['id' => $row['id'] ?? null, 'id_uppercat' => $row['id_uppercat']];
            }

            array_multisort($sort, $order_by_field === 'natural_order' ? SORT_NATURAL : SORT_REGULAR, 'ASC' == $order_by_asc ? SORT_ASC : SORT_DESC, $categories);
            $this->categoryAdminService->saveCategoriesOrder($categories);
            $rawOpenCat = $_POST['id'] ?? null;
            $open_cat = is_string($rawOpenCat) ? $rawOpenCat : '-1';
        }

        $tpl->assign('open_cat', $open_cat);
        $tpl->assign(['F_ACTION' => $this->urlGenerator->admin('albums')]);
        $tpl->assign('delay_before_autoOpen', Config::albumMoveDelayBeforeAutoOpening());
        $tpl->assign('POS_PREF', Config::newcatDefaultPosition());

        $allAlbum = $this->conn->executeQuery('SELECT id,name,`rank`,status, visible, uppercats, lastmodified FROM ' . Tables::categories() . ';')->fetchAllAssociative();

        $associatedTree = [];
        foreach ($allAlbum as $album) {
            $album['name'] = EventDispatcher::dispatch('render_category_name', $album['name'], 'admin_cat_list');
            $album['lastmodified'] = $this->dateService->timeSince(is_string($album['lastmodified']) || is_int($album['lastmodified']) ? $album['lastmodified'] : null, 'year');
            $albumUppercats = $album['uppercats'] ?? null;
            $parents = array_map(strval(...), explode(',', is_string($albumUppercats) ? $albumUppercats : ''));
            self::placeAlbumInTree($associatedTree, $parents, $album);
        }

        $is_forbidden = array_fill_keys(explode(',', is_scalar($user['forbidden_categories'] ?? null) ? (string) $user['forbidden_categories'] : ''), 1);

        $nb_photos_in = array_column($this->conn->executeQuery('SELECT category_id, COUNT(*) AS nb_photos FROM ' . Tables::imageCategory() . ' GROUP BY category_id;')->fetchAllAssociative(), 'nb_photos', 'category_id');

        $all_categories = array_column($this->conn->executeQuery('SELECT id, uppercats FROM ' . Tables::categories() . ';')->fetchAllAssociative(), 'uppercats', 'id');
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
            'PWG_TOKEN'           => $this->csrfService->getToken(),
            'nb_albums'           => $nb_albums,
            'ADMIN_PAGE_TITLE'    => Lang::t('Albums'),
            'light_album_manager' => $light_album_manager,
            'page_data_json'      => json_encode([
                'data'                          => $album_tree,
                'pwg_token'                     => $this->csrfService->getToken(),
                'openCat'                       => is_numeric($open_cat) ? (int) $open_cat : -1,
                'nb_albums'                     => $nb_albums,
                'light_album_manager'           => (bool) $light_album_manager,
                'delay_autoOpen'                => Config::albumMoveDelayBeforeAutoOpening(),
                'x_nb_subcats'                  => Lang::t('%d sub-albums'),
                'x_nb_images'                   => Lang::t('%d photos'),
                'x_nb_sub_photos'               => Lang::t('%d pictures in sub-albums'),
                'str_are_you_sure'              => Lang::t("The status of the album '%s' and its sub-albums will change to private. Are you sure?"),
                'str_yes_change_parent'         => Lang::t('Yes change parent anyway'),
                'str_no_change_parent'          => Lang::t("No, don't move this album here"),
                'str_albs_drag_drop'            => Lang::t('Drag and drop to reorder albums'),
                'delete_album_with_name'        => Lang::t('Delete album "%s".'),
                'delete_album_with_subs'        => Lang::t('Delete album "%s" and its %d sub-albums.'),
                'has_images_associated_outside' => Lang::t('delete album and all %d photos, even the %d associated to other albums'),
                'has_images_becomming_orphans'  => Lang::t('delete album and the %d orphan photos'),
                'rename_item'                   => Lang::t('Rename "%s"'),
                'str_add_album'                 => Lang::t('Add Album'),
                'str_edit_album'                => Lang::t('Edit album'),
                'str_add_photo'                 => Lang::t('Add Photos'),
                'str_visit_gallery'             => Lang::t('Visit Gallery'),
                'str_sort_order'                => Lang::t('Automatic sort order'),
                'str_delete_album'              => Lang::t('Delete album'),
                'str_root_order'                => Lang::t('Apply to root albums'),
                'str_sub_album_order'           => Lang::t('Apply to direct sub-albums'),
                'str_album_name_empty'          => Lang::t('Album name must not be empty'),
                'add_album_root_title'          => Lang::t('Create a new album at root'),
                'add_sub_album_of'              => Lang::t('Create a sub-album of "%s"'),
                'tiptip_locked_album'           => Lang::t('Locked album'),
                'str_albums_found'              => Lang::t('<b>%d</b> albums found'),
                'str_album_found'               => Lang::t('<b>1</b> album found'),
                'str_result_limit'              => Lang::t('<b>%d+</b> albums found, try to refine the search'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'albums.latte');
    }

    // ── album_notification ────────────────────────────────────────────────────

    private function albumNotification(): void
    {
        $tpl = TemplateRegistry::current();
        $category = $this->albumCategory;
        $admin_album_base_url = $this->adminAlbumBaseUrl;

        if ($category === null) {
            $this->inputValidator->check('cat_id', $_GET, false, ValidationPattern::ID);
            $rawCatId2 = $_GET['cat_id'] ?? null;
            $cat_id_str = is_string($rawCatId2) ? $rawCatId2 : '';
            $admin_album_base_url = $this->urlGenerator->admin('album-' . $cat_id_str);
            $category = $this->categoryRepository
                ->findCategoryById(is_numeric($cat_id_str) ? (int) $cat_id_str : 0);
            if ($category === null) {
                throw new ValidationException('Invalid category');
            }
        }

        $pageCat = $category['id'];

        if (isset($_POST['submitEmail'])) {
            $this->csrfService->check();
            $this->urlService->setMakeFullUrl();

            $img = [];
            if (!empty($category['representative_picture_id'])) {
                $element = $this->imageRepository
                    ->findById(is_numeric($category['representative_picture_id']) ? (int) $category['representative_picture_id'] : 0);
                if ($element !== null) {
                    $img = [
                        'link' => $this->urlService->makePictureUrl(['image_id' => $element['id'], 'image_file' => $element['file'], 'category' => $category]),
                        'src'  => DerivativeImage::url(DerivativeSize::Thumb->value, $element),
                    ];
                }
            }

            $renderedName = EventDispatcher::dispatch('render_category_name', $category['name'], 'admin_cat_list');
            $args = ['subject' => Lang::t('[%s] Visit album %s', Config::galleryTitle(), is_string($renderedName) ? $renderedName : '')];
            $mailTpl = [
                'filename' => 'cat_group_info',
                'assign'   => [
                    'IMG'         => $img,
                    'CAT_NAME'    => EventDispatcher::dispatch('render_category_name', $category['name'], 'admin_cat_list'),
                    'LINK'        => $this->urlService->makeIndexUrl(['category' => ['id' => $category['id'], 'name' => EventDispatcher::dispatch('render_category_name', $category['name'], 'admin_cat_list'), 'permalink' => $category['permalink']]]),
                    'CPL_CONTENT' => (isset($_POST['mail_content']) && is_string($_POST['mail_content']) && $_POST['mail_content'] !== '') ? stripslashes($_POST['mail_content']) : '',
                ],
            ];

            $rawPostUsers = $_POST['users'] ?? null;
            if ('users' == $_POST['who'] && isset($_POST['users']) && is_array($rawPostUsers) && count($rawPostUsers) > 0) {
                $this->inputValidator->check('users', $_POST, true, ValidationPattern::ID);
                $query = 'SELECT ui.user_id, ui.status, ui.language, u.' . Config::userFields()['email'] . ' AS email, u.' . Config::userFields()['username'] . ' AS username FROM ' . Tables::userInfos() . ' AS ui JOIN ' . Tables::users() . ' AS u ON u.' . Config::userFields()['id'] . ' = ui.user_id WHERE ui.user_id IN (' . implode(',', array_map(fn (mixed $v): string => is_string($v) ? $v : '', $rawPostUsers)) . ');';
                $users     = $this->conn->executeQuery($query)->fetchAllAssociative();
                $usernames = [];
                foreach ($users as $u) {
                    $usernames[] = is_string($u['username'] ?? null) ? $u['username'] : '';
                    $authkey     = $this->authService->createUserAuthKey(is_numeric($u['user_id']) ? (int) $u['user_id'] : 0, is_string($u['status']) ? $u['status'] : null);
                    $user_tpl    = $mailTpl;
                    if ($authkey !== false) {
                        $user_tpl['assign']['LINK'] = $this->urlService->addUrlParams($mailTpl['assign']['LINK'], ['auth' => $authkey['auth_key']]);
                        if (isset($user_tpl['assign']['IMG']['link'])) {
                            $user_tpl['assign']['IMG']['link'] = $this->urlService->addUrlParams($user_tpl['assign']['IMG']['link'], ['auth' => $authkey['auth_key']]);
                        }
                    }
                    $user_args = $args;
                    if (isset($authkey['auth_key'])) {
                        $user_args['auth_key'] = $authkey['auth_key'];
                    }
                    $this->mailService->switchLangTo(is_string($u['language'] ?? null) ? $u['language'] : '');
                    $this->mailService->pwgMail(is_string($u['email'] ?? null) ? $u['email'] : '', $user_args, $user_tpl);
                    $this->mailService->switchLangBack();
                }
                $message  = Translator::get()->plural('%d mail was sent.', '%d mails were sent.', count($users));
                $message .= ' (' . implode(', ', $usernames) . ')';
                $tpl->assign(['save_success' => $message]);
            } elseif ('group' == $_POST['who'] && isset($_POST['group']) && $_POST['group'] !== '') {
                $this->inputValidator->check('group', $_POST, false, ValidationPattern::ID);
                $postGroupRaw = $_POST['group'];
                $this->mailService->pwgMailGroup(is_numeric($postGroupRaw) ? (int) $postGroupRaw : 0, $args, $mailTpl);
                $post_group_str = is_string($postGroupRaw) ? $postGroupRaw : '0';
                $group_name     = $this->groupRepository->findNameById((int) $post_group_str);
                $tpl->assign(['save_success' => Lang::t('An information email was sent to group "%s"', $group_name)]);
            }

            $this->urlService->unsetMakeFullUrl();
        }

        $catIdScalar = is_string($pageCat) ? $pageCat : '';
        $tpl->assign([
            'CATEGORIES_NAV' => new Html(trim($this->htmlService->getCatDisplayNameFromId($catIdScalar, $this->urlGenerator->admin() . '&page=album-'))),
            'F_ACTION'       => $admin_album_base_url . '-notification',
            'PWG_TOKEN'      => $this->csrfService->getToken(),
        ]);

        if (Config::authKeyDuration() > 0) {
            $strResult = strtotime('now -' . Config::authKeyDuration() . ' second');
            $tpl->assign('auth_key_duration', $this->dateService->timeSince($strResult !== false ? $strResult : null, 'second', null, false));
        }

        $all_group_ids = array_column($this->conn->executeQuery('SELECT id AS group_id FROM `' . Tables::groups() . '`;')->fetchAllAssociative(), 'group_id');

        $group_ids = [];
        if (count($all_group_ids) == 0) {
            $tpl->assign('no_group_in_gallery', true);
        } else {
            if ('private' == $category['status']) {
                $tpl->assign('permission_url', $admin_album_base_url . '-permissions');
                $catIdInt  = is_numeric($category['id']) ? (int) $category['id'] : 0;
                $group_ids = array_column($this->conn->executeQuery('SELECT group_id FROM ' . Tables::groupAccess() . ' WHERE cat_id = ' . $catIdInt . ';')->fetchAllAssociative(), 'group_id');
            } else {
                $group_ids = $all_group_ids;
            }
            if (count($group_ids) > 0) {
                $tpl->assign('group_mail_options', array_column($this->conn->executeQuery('SELECT id, name FROM `' . Tables::groups() . '` WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $group_ids)) . ') ORDER BY name ASC;')->fetchAllAssociative(), 'name', 'id'));
            }
        }

        $all_user_ids = array_column($this->conn->executeQuery('SELECT user_id FROM ' . Tables::userInfos() . " WHERE status != 'guest';")->fetchAllAssociative(), 'user_id');

        if ('private' == $category['status']) {
            $catIdInt2 = is_numeric($category['id']) ? (int) $category['id'] : 0;
            $user_ids_access_indirect = [];
            if (count($group_ids) > 0) {
                $user_ids_access_indirect = array_column($this->conn->executeQuery('SELECT user_id FROM ' . Tables::userGroup() . ' WHERE group_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $group_ids)) . ');')->fetchAllAssociative(), 'user_id');
            }
            $user_ids_access_direct = array_column($this->conn->executeQuery('SELECT user_id FROM ' . Tables::userAccess() . ' WHERE cat_id = ' . $catIdInt2 . ';')->fetchAllAssociative(), 'user_id');
            $user_ids_access = array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_merge($user_ids_access_direct, $user_ids_access_indirect)));
            $user_ids        = array_intersect($user_ids_access, array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $all_user_ids));
        } else {
            $user_ids = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $all_user_ids);
        }

        if (count($user_ids) > 0) {
            $tpl->assign('user_options', array_column($this->conn->executeQuery('SELECT ' . Config::userFields()['id'] . ' AS id, ' . Config::userFields()['username'] . ' AS username FROM ' . Tables::users() . ' WHERE id IN (' . implode(',', $user_ids) . ');')->fetchAllAssociative(), 'username', 'id'));
        }

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'album_notification.latte');
    }

    // ── cat_list ──────────────────────────────────────────────────────────────

    private function catList(): void
    {
        $tpl = TemplateRegistry::current();
        EventDispatcher::notify('loc_begin_cat_list');

        if (!empty($_POST) || isset($_GET['delete'])) {
            $this->csrfService->check();
        }

        $sort_orders = [
            'name ASC'            => Lang::t('Album name, A &rarr; Z'),
            'name DESC'           => Lang::t('Album name, Z &rarr; A'),
            'date_creation DESC'  => Lang::t('Date created, new &rarr; old') . ' ' . Lang::t('(determined from photos)'),
            'date_creation ASC'   => Lang::t('Date created, old &rarr; new') . ' ' . Lang::t('(determined from photos)'),
            'date_available DESC' => Lang::t('Date posted, new &rarr; old') . ' ' . Lang::t('(determined from photos)'),
            'date_available ASC'  => Lang::t('Date posted, old &rarr; new') . ' ' . Lang::t('(determined from photos)'),
        ];

        $this->inputValidator->check('parent_id', $_GET, false, ValidationPattern::ID);

        $base_url   = $this->urlGenerator->admin('cat_list');
        $navigation = '<a href="' . $base_url . '">' . Lang::t('Home') . '</a>';

        $this->albumsTabRenderer->render('list');

        if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
            $photo_deletion_mode = 'no_delete';
            if (isset($_GET['photo_deletion_mode'])) {
                $rawPhotoDeletion = $_GET['photo_deletion_mode'];
                $photo_deletion_mode = is_string($rawPhotoDeletion) ? $rawPhotoDeletion : 'no_delete';
            }
            $this->categoryAdminService->deleteCategories([(int) $_GET['delete']], $photo_deletion_mode);
            $_SESSION['page_infos'] = [Lang::t('Virtual album deleted')];
            $this->categoryAdminService->updateGlobalRank();
            $this->userAdminService->invalidateUserCache();
            $redirect_url = $this->urlGenerator->admin('cat_list');
            if (isset($_GET['parent_id'])) {
                $redirect_url .= '&parent_id=' . (is_string($_GET['parent_id']) ? $_GET['parent_id'] : '');
            }
            $this->redirectResponder->redirect($redirect_url);
        } elseif (isset($_POST['submitAdd'])) {
            $output_create = $this->categoryAdminService->createVirtualCategory(
                is_string($rawVirtualName = $_POST['virtual_name'] ?? null) ? $rawVirtualName : '',
                isset($_GET['parent_id']) ? (is_string($rawParentId = $_GET['parent_id']) ? $rawParentId : null) : null
            );
            $this->userAdminService->invalidateUserCache();
            if (isset($output_create['error'])) {
                PageState::current()->addError(is_string($output_create['error']) ? $output_create['error'] : '');
            } else {
                $edit_url = $this->urlGenerator->admin('album-' . (is_scalar($output_create['id'] ?? '') ? (string) ($output_create['id'] ?? '') : ''));
                PageState::current()->addInfo(new Html((is_scalar($output_create['info'] ?? '') ? (string) ($output_create['info'] ?? '') : '') . ' <a class="icon-pencil" href="' . $edit_url . '">' . Lang::t('Edit album') . '</a>'));
            }
        }

        if (isset($_GET['parent_id'])) {
            $navigation .= Config::levelSeparator();
            $raw_parent_id = $_GET['parent_id'];
            $navigation   .= $this->htmlService->getCatDisplayNameFromId(is_scalar($raw_parent_id) ? (int) $raw_parent_id : 0, $base_url . '&parent_id=');
        }

        $form_action = $this->urlGenerator->admin('cat_list');
        if (isset($_GET['parent_id'])) {
            $form_action .= '&parent_id=' . (is_string($_GET['parent_id']) ? $_GET['parent_id'] : '');
        }
        $sort_orders_checked = array_keys($sort_orders);

        $tpl->assign([
            'ADMIN_PAGE_TITLE'    => Lang::t('Album list management'),
            'CATEGORIES_NAV'      => new Html((string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation))),
            'F_ACTION'            => $form_action,
            'PWG_TOKEN'           => $this->csrfService->getToken(),
            'sort_orders'         => $sort_orders,
            'sort_order_checked'  => array_shift($sort_orders_checked),
        ]);

        $query = 'SELECT id, name, permalink, dir, `rank`, status FROM ' . Tables::categories();
        if (!isset($_GET['parent_id'])) {
            $query .= ' WHERE id_uppercat IS NULL';
        } else {
            $query .= ' WHERE id_uppercat = ' . (is_numeric($_GET['parent_id']) ? (int) $_GET['parent_id'] : 0);
        }
        $query .= ' ORDER BY `rank` ASC;';
        $categories = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), null, 'id');

        $nb_photos_in  = [];
        $nb_sub_photos = [];
        $subcats_of    = [];

        if (count($categories)) {
            $nb_photos_in    = array_column($this->conn->executeQuery('SELECT category_id, COUNT(*) AS nb_photos FROM ' . Tables::imageCategory() . ' GROUP BY category_id;')->fetchAllAssociative(), 'nb_photos', 'category_id');
            $all_categories  = array_column($this->conn->executeQuery('SELECT id, uppercats FROM ' . Tables::categories() . ';')->fetchAllAssociative(), 'uppercats', 'id');

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
        $base_url = $this->urlGenerator->admin() . '&page=';

        $parentIdStr = isset($_GET['parent_id']) ? (is_string($_GET['parent_id']) ? $_GET['parent_id'] : '') : null;
        if ($parentIdStr !== null) {
            $tpl->assign('PARENT_EDIT', $base_url . 'album-' . $parentIdStr);
        }

        foreach ($categories as $category) {
            $cat_list_url = $base_url . 'cat_list';
            $self_url     = $cat_list_url;
            if ($parentIdStr !== null) {
                $self_url .= '&parent_id=' . $parentIdStr;
            }

            $catIdStr = (string) (is_numeric($category['id']) ? (int) $category['id'] : 0);
            $tpl_cat  = [
                'NAME'             => EventDispatcher::dispatch('render_category_name', $category['name'], 'admin_cat_list'),
                'NB_PHOTOS'        => $nb_photos_in[$catIdStr] ?? 0,
                'NB_SUB_PHOTOS'    => $nb_sub_photos[$catIdStr] ?? 0,
                'NB_SUB_ALBUMS'    => isset($subcats_of[$catIdStr]) ? count($subcats_of[$catIdStr]) : 0,
                'ID'               => $category['id'],
                'RANK'             => (is_numeric($category['rank'] ?? null) ? (int) $category['rank'] : 0) * 10,
                'U_JUMPTO'         => $this->urlService->makeIndexUrl(['category' => $category]),
                'U_CHILDREN'       => $cat_list_url . '&parent_id=' . (is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''),
                'U_EDIT'           => $base_url . 'album-' . (is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''),
                'U_ADD_PHOTOS_ALBUM' => $base_url . 'photos_add&album=' . (is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''),
                'U_MOVE'           => $base_url . 'albums#cat-' . (is_scalar($category['id'] ?? null) ? (string) $category['id'] : ''),
                'IS_VIRTUAL'       => empty($category['dir']),
                'CAT_ADMIN_ACCESS' => $this->userAdminService->catAdminAccess(is_numeric($category['id'] ?? null) ? (int) $category['id'] : 0),
            ];

            if (empty($category['dir'])) {
                $tpl_cat['U_DELETE']  = $self_url . '&delete=' . (is_scalar($category['id'] ?? null) ? (string) $category['id'] : '');
                $tpl_cat['U_DELETE'] .= '&pwg_token=' . $this->csrfService->getToken();
            } elseif (Config::enableSynchronization()) {
                $tpl_cat['U_SYNC'] = $base_url . 'site_update&site=1&cat_id=' . (is_scalar($category['id'] ?? null) ? (string) $category['id'] : '');
            }

            $tpl->append('categories', $tpl_cat);
        }

        EventDispatcher::notify('loc_end_cat_list');
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'cat_list.latte');
    }

    // ── cat_modify ────────────────────────────────────────────────────────────

    private function catModify(): void
    {
        $tpl = TemplateRegistry::current();
        EventDispatcher::notify('loc_begin_cat_modify');

        if (!isset($_GET['cat_id']) || !is_numeric($_GET['cat_id'])) {
            throw new \InvalidArgumentException('missing cat_id param');
        }

        $category = $this->albumCategory;

        if ($category === null) {
            $cat_id_str = $_GET['cat_id'];
            $category   = $this->categoryRepository->findCategoryById((int) $cat_id_str);
            if ($category === null) {
                throw new ValidationException('Invalid category');
            }
        }

        foreach (['comment', 'dir', 'site_id', 'id_uppercat'] as $nullable) {
            if (!isset($category[$nullable])) {
                $category[$nullable] = '';
            }
        }

        // typed locals — $category is array<string, mixed>
        $catId        = is_scalar($category['id'] ?? null) ? (string) $category['id'] : '0';
        $catIntId     = is_numeric($category['id']) ? (int) $category['id'] : 0;
        $catName      = is_scalar($category['name'] ?? null) ? (string) $category['name'] : '';
        $catComment   = is_string($category['comment']) ? $category['comment'] : '';
        $catVisible   = is_string($category['visible'] ?? null) ? $category['visible'] : 'false';
        $catUppercats = is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '';
        $catUppercat  = is_scalar($category['id_uppercat']) ? (string) $category['id_uppercat'] : '';
        $catSiteId    = is_scalar($category['site_id']) ? (string) $category['site_id'] : '';
        $catLastmod   = is_string($category['lastmodified'] ?? null) || is_int($category['lastmodified'] ?? null) ? $category['lastmodified'] : null;
        $catRepPic    = is_scalar($category['representative_picture_id'] ?? null) ? (string) $category['representative_picture_id'] : '';
        $catComment_b = is_string($category['commentable'] ?? null) ? $category['commentable'] : 'false';

        $category['is_virtual'] = empty($category['dir']);
        $category['has_images'] = $this->categoryRepository->hasCategoryImages($catIntId);
        $subcat_ids             = $this->categoryService->getSubcatIds([$catId]);
        $category['nb_subcats'] = count($subcat_ids) - 1;

        $navigation = $this->htmlService->getCatDisplayNameCache($catUppercats, $this->urlGenerator->admin() . '&page=album-');

        $uppercats_array = explode(',', $catUppercats);
        if (count($uppercats_array) > 1) {
            array_pop($uppercats_array);
            $parent_navigation = $this->htmlService->getCatDisplayNameCache(implode(',', $uppercats_array), $this->urlGenerator->admin() . '&page=album-');
        } else {
            $parent_navigation = Lang::t('Root');
        }

        $base_url     = $this->urlGenerator->admin() . '&page=';
        $cat_list_url = $base_url . 'albums';
        $self_url     = $cat_list_url;
        if ($catUppercat !== '') {
            $self_url .= '&parent_id=' . $catUppercat;
        }

        PageState::current()->addWarning(new Html(Lang::t('This album is currently locked, visible only to administrators.') . '<span class="icon-cone unlock-album">' . Lang::t('Unlock it') . '</span>'));

        $tpl->assign([
            'CATEGORIES_NAV'        => new Html((string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation))),
            'CATEGORIES_PARENT_NAV' => new Html((string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $parent_navigation))),
            'PARENT_CAT_ID'         => $catUppercat !== '' ? $catUppercat : 0,
            'CAT_ID'                => $catId,
            'CAT_NAME'              => htmlspecialchars($catName),
            'CAT_COMMENT'           => htmlspecialchars($catComment),
            'IS_VISIBLE'            => BoolUtil::toString($catVisible),
            'CAT_ADMIN_ACCESS'      => $this->userAdminService->catAdminAccess($catIntId),
            'U_DELETE'              => $base_url . 'albums',
            'U_JUMPTO'              => $this->urlService->makeIndexUrl(['category' => $category]),
            'U_ADD_PHOTOS_ALBUM'    => $base_url . 'photos_add&album=' . $catId,
            'U_CHILDREN'            => $cat_list_url . '&parent_id=' . $catId,
            'U_MOVE'                => $base_url . 'albums&parent_id=' . $catId,
            'U_ACTIVITY'            => $this->urlGenerator->admin('user_activity') . '&album=' . $catId,
        ]);

        if (Config::activateComments()) {
            $tpl->assign('CAT_COMMENTABLE', BoolUtil::toString($catComment_b));
        }

        $image_count = 0;
        $info_title  = '';
        if ($category['has_images']) {
            $tpl->assign('U_MANAGE_ELEMENTS', $base_url . 'batch_manager&filter=album-' . $catId);
            [$image_count, $min_date, $max_date] = $this->categoryRepository->findImageStats($catIntId);
            $min_date = (string) $min_date;
            $max_date = (string) $max_date;
            $info_title = ($min_date == $max_date)
                ? Lang::t('This album contains %d photos, added on %s.', $image_count, $this->dateService->formatDate($min_date))
                : Lang::t('This album contains %d photos, added between %s and %s.', $image_count, $this->dateService->formatDate($min_date), $this->dateService->formatDate($max_date));
        }
        $tpl->assign(['INFO_PHOTO' => Lang::t('%d photos', $image_count), 'INFO_TITLE' => $info_title]);

        $category['nb_images_recursive'] = count(array_column($this->conn->executeQuery('SELECT DISTINCT (image_id) FROM ' . Tables::imageCategory() . ' WHERE category_id IN (' . implode(',', $subcat_ids) . ');')->fetchAllAssociative(), 'image_id'));

        $result = $this->conn->executeQuery('SELECT occured_on FROM `' . Tables::activity() . '` WHERE object_id = ' . $catIntId . ' AND object = "album" AND action = "add"')->fetchAllAssociative();
        if (count($result) > 0) {
            $occurred_on = is_scalar($result[0]['occured_on']) ? (string) $result[0]['occured_on'] : '';
            $tpl->assign(['INFO_CREATION_SINCE' => $this->dateService->timeSince($occurred_on, 'day', null, true, true, true), 'INFO_CREATION' => $this->dateService->formatDate($occurred_on, ['day', 'month', 'year'])]);
        }

        $result = $this->conn->executeQuery('SELECT COUNT(*) FROM `' . Tables::categories() . '` WHERE id_uppercat = ' . $catIntId)->fetchAllAssociative();
        $countRaw = $result[0]['COUNT(*)'] ?? 0;
        $tpl->assign(['INFO_DIRECT_SUB' => Lang::t('%d sub-albums', is_numeric($countRaw) ? (int) $countRaw : 0)]);

        $tpl->assign([
            'INFO_ID'                  => Lang::t('Numeric identifier : %d', $catId),
            'INFO_LAST_MODIFIED_SINCE' => $this->dateService->timeSince($catLastmod, 'minute', null, true, true, true),
            'INFO_LAST_MODIFIED'       => $this->dateService->formatDate($catLastmod, ['day', 'month', 'year']),
            'INFO_IMAGES_RECURSIVE'    => Lang::t('%d including sub-albums', $category['nb_images_recursive']),
            'INFO_SUBCATS'             => Lang::t('%d in whole branch', $category['nb_subcats']),
            'NB_SUBCATS'               => $category['nb_subcats'],
        ]);

        $tpl->assign(['U_MANAGE_RANKS' => $base_url . 'element_set_ranks&cat_id=' . $catId, 'CACHE_KEYS' => $this->adminService->getAdminClientCacheKeys(['categories'])]);

        if (!$category['is_virtual']) {
            /** @phpstan-ignore-next-line argument.type */
            $category['cat_full_dir'] = $this->getCompleteDir($_GET['cat_id']);
            $catFullDirRaw = $category['cat_full_dir'];
            $category_full_dir = preg_replace('/\/$/', '', $catFullDirRaw) ?? '';
            $tpl->assign(['CAT_FULL_DIR' => $category_full_dir]);
            $tpl->assign('CAT_DIR_NAME', basename($category_full_dir));
            $tpl->assign('CAT_MIN_DIR', $this->getMinLocalDir($category_full_dir));
            if (Config::enableSynchronization()) {
                $tpl->assign('U_SYNC', $base_url . 'site_update&site=' . $catSiteId . '&cat_id=' . $catId);
            }
        }

        if ($category['has_images'] || $catRepPic !== '') {
            $tpl_representant = [];
            if ($catRepPic !== '') {
                $tpl_representant['picture'] = $this->imageAdminService->getCategoryRepresentantProperties($catRepPic, DerivativeSize::Medium->value);
            }
            $tpl_representant['ALLOW_SET_RANDOM'] = $category['has_images'];
            if (($category['has_images'] && Config::allowRandomRepresentative()) || (!$category['has_images'] && $catRepPic !== '')) {
                $tpl_representant['ALLOW_DELETE'] = true;
            }
            $tpl->assign('representant', $tpl_representant);
        }

        if ($category['is_virtual']) {
            $tpl->assign('parent_category', $catUppercat === '' ? [] : [$catUppercat]);
        }

        $tpl->assign('PWG_TOKEN', $this->csrfService->getToken());
        $pwg_token     = $this->csrfService->getToken();
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
            'str_cancel'                           => Lang::t('No, I have changed my mind'),
            'str_delete_album'                     => Lang::t('Delete album'),
            'str_delete_album_and_his_x_subalbums' => Lang::t('Delete album "%s" and its %d sub-albums.'),
            'str_just_now'                         => Lang::t('Just now'),
            'str_dont_delete_photos'               => Lang::t('delete only album, not photos'),
            'str_delete_orphans'                   => Lang::t('delete album and the %d orphan photos'),
            'str_delete_all_photos'                => Lang::t('delete album and all %d photos, even the %d associated to other albums'),
            'str_album_comment_allow'              => Lang::t('Comments allowed for sub-albums'),
            'str_album_comment_disallow'           => Lang::t('Comments disallowed for sub-albums'),
            'str_modal_ab'                         => Lang::t('New parent album'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        EventDispatcher::notify('loc_end_cat_modify');
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'cat_modify.latte');
    }

    // ── cat_options ───────────────────────────────────────────────────────────

    private function catOptions(): void
    {
        $tpl = TemplateRegistry::current();
        if (!empty($_POST)) {
            $this->csrfService->check();
            $this->inputValidator->check('cat_true', $_POST, true, ValidationPattern::ID);
            $this->inputValidator->check('cat_false', $_POST, true, ValidationPattern::ID);
            $this->inputValidator->check('section', $_GET, false, '/^[a-z0-9_-]+$/i');
        }

        if (isset($_POST['falsify']) && isset($_POST['cat_true']) && count(is_array($_POST['cat_true']) ? $_POST['cat_true'] : []) > 0) {
            /** @var int[] $cat_true */
            $cat_true        = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['cat_true']) ? $_POST['cat_true'] : []);
            $current_section = $_GET['section'] ?? '';
            match ($current_section) {
                'comments'       => $this->categoryRepository->setCommentable($cat_true, false),
                'visible'        => $this->categoryAdminService->setCatVisible($cat_true, 'false'),
                'status'         => $this->categoryAdminService->setCatStatus($cat_true, 'private'),
                'representative' => $this->categoryRepository->clearRepresentatives($cat_true),
                default          => null,
            };
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $cat_true, 'edit', ['section' => $current_section, 'action' => 'falsify']));
        } elseif (isset($_POST['trueify']) && isset($_POST['cat_false']) && count(is_array($_POST['cat_false']) ? $_POST['cat_false'] : []) > 0) {
            /** @var int[] $cat_false */
            $cat_false       = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['cat_false']) ? $_POST['cat_false'] : []);
            $current_section = $_GET['section'] ?? '';
            match ($current_section) {
                'comments'       => $this->categoryRepository->setCommentable($cat_false, true),
                'visible'        => $this->categoryAdminService->setCatVisible($cat_false, 'true'),
                'status'         => $this->categoryAdminService->setCatStatus($cat_false, 'public'),
                'representative' => $this->categoryAdminService->setRandomRepresentant($cat_false),
                default          => null,
            };
            $this->activityLogger->log(new ActivityEvent(ActivityObject::Album, $cat_false, 'edit', ['section' => $current_section, 'action' => 'trueify']));
        }

        $get_section = $_GET['section'] ?? null;
        $section     = is_string($get_section) ? $get_section : 'status';
        $base_url    = $this->urlGenerator->admin('cat_options') . '&section=';

        $tpl->assign(['U_HELP' => $this->urlGenerator->adminPopupHelp('cat_options'), 'F_ACTION' => $base_url . $section]);

        $tabsheet = new Tabsheet();
        $tabsheet->setId('cat_options');
        $tabsheet->select($section);
        $tabsheet->assign();

        $query_true = $query_false = '';
        if ($section === 'comments') {
            $query_true  = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . " WHERE commentable = 'true';";
            $query_false = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . " WHERE commentable = 'false';";
            $tpl->assign(['L_SECTION' => Lang::t('Authorize users to add comments on selected albums'), 'L_CAT_OPTIONS_TRUE' => Lang::t('Authorized'), 'L_CAT_OPTIONS_FALSE' => Lang::t('Forbidden')]);
        } elseif ($section === 'visible') {
            $query_true  = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . " WHERE visible = 'true';";
            $query_false = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . " WHERE visible = 'false';";
            $tpl->assign(['L_SECTION' => Lang::t('Lock albums'), 'L_CAT_OPTIONS_TRUE' => Lang::t('Unlocked'), 'L_CAT_OPTIONS_FALSE' => Lang::t('Locked')]);
        } elseif ($section === 'status') {
            $query_true  = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . " WHERE status = 'public';";
            $query_false = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . " WHERE status = 'private';";
            $tpl->assign(['L_SECTION' => Lang::t('Manage authorizations for selected albums'), 'L_CAT_OPTIONS_TRUE' => Lang::t('Public'), 'L_CAT_OPTIONS_FALSE' => Lang::t('Private')]);
        } elseif ($section === 'representative') {
            $query_true  = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . ' WHERE representative_picture_id IS NOT NULL;';
            $query_false = 'SELECT DISTINCT id,name,uppercats,global_rank FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id=category_id WHERE representative_picture_id IS NULL;';
            $tpl->assign(['L_SECTION' => Lang::t('Representative'), 'L_CAT_OPTIONS_TRUE' => Lang::t('singly represented'), 'L_CAT_OPTIONS_FALSE' => Lang::t('randomly represented')]);
        }

        $this->categoryService->displaySelectCatWrapper($query_true, [], 'category_option_true');
        $this->categoryService->displaySelectCatWrapper($query_false, [], 'category_option_false');
        $tpl->assign('PWG_TOKEN', $this->csrfService->getToken());
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Properties of abums'));
        $tpl->assignVarFromTemplate('DOUBLE_SELECT', 'double_select.latte');
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'cat_options.latte');
    }

    // ── cat_perm ──────────────────────────────────────────────────────────────

    private function catPerm(): void
    {
        $tpl = TemplateRegistry::current();
        $this->inputValidator->check('cat_id', $_GET, false, ValidationPattern::ID);
        $rawCatId3 = $_GET['cat_id'] ?? null;
        $cat_id = is_string($rawCatId3) ? $rawCatId3 : '';
        if ($cat_id === '') {
            throw new ValidationException('No category selected');
        }

        $category = $this->categoryService->getCatInfo(is_numeric($cat_id) ? (int) $cat_id : 0);
        if ($category === null) {
            throw new ValidationException('Invalid category');
        }
        $pageCat = is_numeric($category['id'] ?? null) ? (int) $category['id'] : 0;

        $admin_album_base_url = $this->adminAlbumBaseUrl !== '' ? $this->adminAlbumBaseUrl : $this->urlGenerator->admin('album-' . $cat_id);

        if (!empty($_POST)) {
            $this->csrfService->check();

            $rawPostStatus = $_POST['status'] ?? null;
            $post_status = is_string($rawPostStatus) ? $rawPostStatus : '';
            if ($category['status'] != $post_status || ($category['status'] != 'public' && isset($_POST['apply_on_sub']))) {
                $cat_ids = [$pageCat];
                if (isset($_POST['apply_on_sub'])) {
                    $cat_ids = array_merge($cat_ids, $this->categoryService->getSubcatIds([$pageCat]));
                }
                $this->categoryAdminService->setCatStatus($cat_ids, $post_status);
                $category['status'] = $post_status;
            }

            if ('private' == $post_status) {
                $groups_granted     = array_column($this->conn->executeQuery('SELECT group_id FROM ' . Tables::groupAccess() . ' WHERE cat_id = ' . $pageCat . ';')->fetchAllAssociative(), 'group_id');
                if (!isset($_POST['groups'])) {
                    $_POST['groups'] = [];
                }
                /** @var int[] $post_groups */
                $post_groups        = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['groups']) ? $_POST['groups'] : []);
                /** @var int[] $groups_granted_int */
                $groups_granted_int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $groups_granted);

                $deny_groups = array_diff($groups_granted_int, $post_groups);
                if (count($deny_groups) > 0) {
                    $this->permissionRepository->deleteGroupAccess(array_map(intval(...), $deny_groups), array_map(intval(...), $this->categoryService->getSubcatIds([$pageCat])));
                }

                $grant_groups = $post_groups;
                if (count($grant_groups) > 0) {
                    $cat_ids = $this->categoryAdminService->getUppercatIds([$pageCat]);
                    if (isset($_POST['apply_on_sub'])) {
                        $cat_ids = array_merge($cat_ids, $this->categoryService->getSubcatIds([$pageCat]));
                    }
                    $private_cats = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', array_map(fn (int|string $v): string => (string) $v, $cat_ids)) . ") AND status = 'private';")->fetchAllAssociative(), 'id');
                    $inserts = [];
                    foreach ($private_cats as $cid) {
                        foreach ($grant_groups as $gid) {
                            $inserts[] = ['group_id' => $gid, 'cat_id' => $cid];
                        }
                    }
                    Dml::massInserts(Tables::groupAccess(), ['group_id', 'cat_id'], $inserts, ['ignore' => true]);
                }

                $users_granted     = array_column($this->conn->executeQuery('SELECT user_id FROM ' . Tables::userAccess() . ' WHERE cat_id = ' . $pageCat . ';')->fetchAllAssociative(), 'user_id');
                if (!isset($_POST['users'])) {
                    $_POST['users'] = [];
                }
                /** @var int[] $post_users */
                $post_users        = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['users']) ? $_POST['users'] : []);
                /** @var int[] $users_granted_int */
                $users_granted_int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $users_granted);

                $deny_users = array_diff($users_granted_int, $post_users);
                if (count($deny_users) > 0) {
                    $this->permissionRepository->deleteUserAccess(array_map(intval(...), $deny_users), array_map(intval(...), $this->categoryService->getSubcatIds([$pageCat])));
                }
                if (count($post_users) > 0) {
                    $this->categoryAdminService->addPermissionOnCategory($pageCat, $post_users);
                }
            }

            $tpl->assign(['save_success' => Lang::t('Album updated successfully')]);
        }

        $tpl->assign([
            'CATEGORIES_NAV' => new Html($this->htmlService->getCatDisplayNameFromId($pageCat, $this->urlGenerator->admin() . '&page=album-')),
            'U_HELP'         => $this->urlGenerator->adminPopupHelp('cat_perm'),
            'F_ACTION'       => $admin_album_base_url . '-permissions',
            'private'        => ('private' == $category['status']),
        ]);

        $groups          = array_column($this->conn->executeQuery('SELECT id, name FROM `' . Tables::groups() . '` ORDER BY name ASC;')->fetchAllAssociative(), 'name', 'id');
        $group_granted_ids = array_column($this->conn->executeQuery('SELECT group_id FROM ' . Tables::groupAccess() . ' WHERE cat_id = ' . $pageCat . ';')->fetchAllAssociative(), 'group_id');
        $users           = array_column($this->conn->executeQuery('SELECT ' . Config::userFields()['id'] . ' AS id, ' . Config::userFields()['username'] . ' AS username FROM ' . Tables::users() . ';')->fetchAllAssociative(), 'username', 'id');
        $user_granted_direct_ids = array_column($this->conn->executeQuery('SELECT user_id FROM ' . Tables::userAccess() . ' WHERE cat_id = ' . $pageCat . ';')->fetchAllAssociative(), 'user_id');

        $tpl->assign('groups', $groups);
        $tpl->assign('groups_selected', $group_granted_ids);
        $tpl->assign('users', $users);
        $tpl->assign('users_selected', $user_granted_direct_ids);

        $user_granted_indirect_ids = [];
        if (count($group_granted_ids) > 0) {
            $granted_groups = [];
            foreach ($this->groupRepository->findUserGroupMembersByGroupIds(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $group_granted_ids)) as $row) {
                $row_group_id = is_scalar($row['group_id'] ?? null) ? (string) $row['group_id'] : '';
                if (!isset($granted_groups[$row_group_id])) {
                    $granted_groups[$row_group_id] = [];
                }
                $granted_groups[$row_group_id][] = is_scalar($row['user_id'] ?? null) ? (string) $row['user_id'] : '';
            }
            $user_granted_by_group_ids = [];
            foreach ($granted_groups as $group_users) {
                $user_granted_by_group_ids = array_merge($user_granted_by_group_ids, $group_users);
            }
            $user_granted_by_group_ids = array_unique($user_granted_by_group_ids);
            $user_granted_indirect_ids = array_diff($user_granted_by_group_ids, array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $user_granted_direct_ids));

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

        $cache_keys = $this->adminService->getAdminClientCacheKeys(['groups', 'users']);
        $tpl->assign([
            'PWG_TOKEN'               => $this->csrfService->getToken(),
            'INHERIT'                 => Config::inheritanceByDefault(),
            'CACHE_KEYS'              => $cache_keys,
            'cat_perm_page_data_json' => json_encode(['CACHE_KEYS' => $cache_keys, 'ROOT_URL' => UrlService::getRootUrl(), 'str_create' => Lang::t('Create'), 'has_indirect_perms' => count($user_granted_indirect_ids) > 0]),
        ]);

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'cat_perm.latte');
    }

    // ── element_set_ranks ─────────────────────────────────────────────────────

    private function elementSetRanks(): void
    {
        $tpl = TemplateRegistry::current();
        $sort_fields = [
            '' => '', 'file ASC' => Lang::t('File name, A &rarr; Z'), 'file DESC' => Lang::t('File name, Z &rarr; A'),
            'name ASC' => Lang::t('Photo title, A &rarr; Z'), 'name DESC' => Lang::t('Photo title, Z &rarr; A'),
            'date_creation DESC' => Lang::t('Date created, new &rarr; old'), 'date_creation ASC' => Lang::t('Date created, old &rarr; new'),
            'date_available DESC' => Lang::t('Date posted, new &rarr; old'), 'date_available ASC' => Lang::t('Date posted, old &rarr; new'),
            'rating_score DESC' => Lang::t('Rating score, high &rarr; low'), 'rating_score ASC' => Lang::t('Rating score, low &rarr; high'),
            'hit DESC' => Lang::t('Visits, high &rarr; low'), 'hit ASC' => Lang::t('Visits, low &rarr; high'),
            'id ASC' => Lang::t('Numeric identifier, 1 &rarr; 9'), 'id DESC' => Lang::t('Numeric identifier, 9 &rarr; 1'),
            'rank ASC' => Lang::t('Manual sort order'),
        ];

        if (!isset($_GET['cat_id']) || !is_numeric($_GET['cat_id'])) {
            throw new \InvalidArgumentException('missing cat_id param');
        }

        $categoryId  = $_GET['cat_id'];
        $image_order_choices  = ['default', 'rank', 'user_define'];
        $image_order_choice   = 'default';

        if (isset($_POST['submit'])) {
            if (isset($_POST['rank_of_image'])) {
                $rank_of_image = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['rank_of_image']) ? $_POST['rank_of_image'] : []);
                asort($rank_of_image, SORT_NUMERIC);
                $this->categoryAdminService->saveImagesOrder((int) $categoryId, array_keys($rank_of_image));
            }

            if (isset($_POST['image_order_choice']) && is_string($_POST['image_order_choice']) && $_POST['image_order_choice'] !== '' && in_array($_POST['image_order_choice'], $image_order_choices)) {
                $image_order_choice = $_POST['image_order_choice'];
            }

            $message     = Lang::t('Album updated successfully');
            $image_order = null;
            if ($image_order_choice == 'user_define') {
                $postImageOrder = $_POST['image_order'] ?? null;
                $image_order_post = is_array($postImageOrder) ? $postImageOrder : [];
                for ($i = 0; $i < 3; $i++) {
                    $rawOrderVal = $image_order_post[$i] ?? null;
                    $image_order_val = is_string($rawOrderVal) ? $rawOrderVal : null;
                    if ($image_order_val !== null && $image_order_val !== '' && in_array($image_order_val, array_keys($sort_fields))) {
                        $image_order = ($image_order !== null ? $image_order . ',' : '') . $image_order_val;
                    }
                }
            } elseif ($image_order_choice == 'rank') {
                $image_order = '`rank` ASC';
                $message     = Lang::t('Images manual order was saved');
            }

            $category_id_int = (int) $categoryId;
            $catRepo = $this->categoryRepository;
            $catRepo->updateImageOrder($category_id_int, $image_order ?? null);

            if (isset($_POST['image_order_subcats'])) {
                $cat_info = $this->categoryService->getCatInfo((string) $category_id_int);
                $catInfoUppercats = $cat_info !== null ? ($cat_info['uppercats'] ?? null) : null;
                $catRepo->updateImageOrderForSubcats(is_scalar($catInfoUppercats) ? (string) $catInfoUppercats : '', $image_order ?? null);
            }

            $tpl->assign(['save_success' => $message]);
        }

        $base_url = $this->urlGenerator->admin();
        $category = $this->categoryRepository->findCategoryById((int) $categoryId);

        if ($category !== null && ($category['image_order'] == 'rank ASC' || $category['image_order'] == '`rank` ASC')) {
            $image_order_choice = 'rank';
        } elseif ($category !== null && $category['image_order'] != '') {
            $image_order_choice = 'user_define';
        }

        $categoryUppercats = $category !== null ? ($category['uppercats'] ?? null) : null;
        $navigation = $this->htmlService->getCatDisplayNameCache(is_scalar($categoryUppercats) ? (string) $categoryUppercats : '', $this->urlGenerator->admin() . '&page=album-');
        $tpl->assign(['CATEGORIES_NAV' => new Html((string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation))), 'F_ACTION' => $base_url . $this->urlService->getQueryStringDiff([])]);

        $imgRows = $this->imageRepository->findByCategoryIdOrdered((int) $categoryId);
        if (count($imgRows) > 0) {
            $current_rank     = 1;
            $derivativeParams = ImageStdParams::getByType(DerivativeSize::Square->value);
            foreach ($imgRows as $row) {
                $derivative     = new DerivativeImage($derivativeParams, new SrcImage($row));
                $thumbnail_name = !empty($row['name']) ? $row['name'] : str_replace('_', ' ', $this->stringUtil->getFilenameWoExtension(is_string($row['file'] ?? null) ? $row['file'] : ''));
                $current_rank++;
                $tpl->append('thumbnails', ['ID' => $row['id'], 'NAME' => $thumbnail_name, 'TN_SRC' => $derivative->getUrl(), 'RANK' => $current_rank * 10, 'SIZE' => $derivative->getSize()]);
            }
        }

        $tpl->assign('image_order_options', $sort_fields);
        $categoryImageOrder = $category !== null ? ($category['image_order'] ?? null) : null;
        $image_order = explode(',', is_scalar($categoryImageOrder) ? (string) $categoryImageOrder : '');
        for ($i = 0; $i < 3; $i++) {
            $tpl->append('image_order', $image_order[$i] ?? '');
        }
        $tpl->assign('image_order_choice', $image_order_choice);
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'element_set_ranks.latte');
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
        $category_ids = $this->categoryService->getSubcatIds($ids);

        $ref_dates = array_column($this->conn->executeQuery('SELECT category_id, ' . $minmax . '(' . $field . ') as ref_date FROM ' . Tables::imageCategory() . ' JOIN ' . Tables::images() . ' ON image_id = id WHERE category_id IN (' . implode(',', $category_ids) . ') GROUP BY category_id;')->fetchAllAssociative(), 'ref_date', 'category_id');

        $uppercats_of = array_column($this->conn->executeQuery('SELECT id, uppercats FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $category_ids) . ');')->fetchAllAssociative(), 'uppercats', 'id');

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
            $catId       = is_scalar($catData['id'] ?? null) ? (string) $catData['id'] : '';
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
        $local_dir = '';
        $uppercats = $this->categoryRepository->findUppercatsStringById((int) $category_id) ?? '';

        $upper_array   = explode(',', $uppercats);
        $database_dirs = $this->categoryRepository->findIdDirMap(array_map(intval(...), $upper_array));
        foreach ($upper_array as $id) {
            $dirEntry = $database_dirs[(int) $id] ?? null;
            $local_dir .= ($dirEntry ?? '') . '/';
        }
        return $local_dir;
    }

    private function getSiteUrl(string $category_id): string
    {
        return $this->categoryRepository->findGalleriesUrlByCategoryId((int) $category_id) ?? '';
    }

    private function getMinLocalDir(string $local_dir): string
    {
        $full_dir = explode('/', $local_dir);
        if (count($full_dir) <= 3) {
            return $local_dir;
        }
        return $full_dir[0] . '/' . $full_dir[1] . '/&hellip;/' . end($full_dir);
    }

    /**
     * Recursively place an album into the associated-tree structure.
     * Uses copy-and-reassign instead of PHP references to stay PHPStan-compatible.
     *
     * @param array<mixed> &$tree
     * @param list<string> $keys
     * @param array<mixed> $album
     */
    private static function placeAlbumInTree(array &$tree, array $keys, array $album): void
    {
        $key = array_shift($keys);
        if ($key === null) {
            return;
        }
        if (!isset($tree[$key]) || !is_array($tree[$key])) {
            $tree[$key] = ['children' => [], 'cat' => null];
        }
        $node = $tree[$key];
        if (count($keys) === 0) {
            $node['cat'] = $album;
            $tree[$key] = $node;
        } else {
            $children = is_array($node['children']) ? $node['children'] : [];
            self::placeAlbumInTree($children, $keys, $album);
            $node['children'] = $children;
            $tree[$key] = $node;
        }
    }
}
