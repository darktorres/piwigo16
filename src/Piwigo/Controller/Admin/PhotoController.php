<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\AdminService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Upload\DirectPreparer;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Cache\RequestCache;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Dml;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Rate\RateRepository;
use Piwigo\Tag\TagRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Url\UrlService;

final class PhotoController
{
    /** @var list<string> */
    public const array PAGES = [
        'photo',
        'picture_modify',
        'picture_coi',
        'picture_formats',
        'photos_add',
        'photos_add_direct',
        'photos_add_ftp',
        'photos_add_applications',
    ];

    private string $adminPhotoBaseUrl = '';

    public function handle(string $page): void
    {
        if ($page === 'photo') {
            $this->photo();
        } elseif ($page === 'picture_modify') {
            $this->pictureModify();
        } elseif ($page === 'picture_coi') {
            $this->pictureCoi();
        } elseif ($page === 'picture_formats') {
            $this->pictureFormats();
        } elseif ($page === 'photos_add') {
            $this->photosAdd();
        } elseif ($page === 'photos_add_direct') {
            $this->photosAddDirect();
        } elseif ($page === 'photos_add_ftp') {
            $this->photosAddFtp();
        } elseif ($page === 'photos_add_applications') {
            $this->photosAddApplications();
        }
    }

    // ── photo ─────────────────────────────────────────────────────────────────

    private function photo(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        check_input_parameter('cat_id', $_GET, false, ValidationPattern::ID);
        check_input_parameter('image_id', $_GET, false, ValidationPattern::ID);

        $image_id_str = is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : '';
        $this->adminPhotoBaseUrl = ServiceLocator::get(UrlGenerator::class)->admin('photo-' . $image_id_str);
        $GLOBALS['admin_photo_base_url'] = $this->adminPhotoBaseUrl;

        $page['image'] = ServiceLocator::get(ImageAdminService::class)->getImageInfos($image_id_str, true);

        if (isset($_GET['cat_id'])) {
            $GLOBALS['category'] = ServiceLocator::get(CategoryRepository::class)
                ->findCategoryById(is_scalar($_GET['cat_id']) ? (int) $_GET['cat_id'] : 0);
        }

        $page['tab'] = 'properties';
        if (isset($_GET['tab'])) {
            $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'properties';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('photo');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        $tpl->assign([
            'ADMIN_PAGE_TITLE' => l10n('Edit photo') . ' <span class="image-id">#' . $image_id_str . '</span>',
        ]);

        $tab = (string) $page['tab'];
        if ($tab === 'properties') {
            $this->pictureModify();
        } elseif ($tab === 'coi') {
            $this->pictureCoi();
        } elseif ($tab === 'formats' && Config::isFormatsEnabled()) {
            $this->pictureFormats();
        }
    }

    // ── picture_modify ────────────────────────────────────────────────────────

    private function pictureModify(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];
        check_input_parameter('image_id', $_GET, false, ValidationPattern::ID);
        check_input_parameter('level', $_POST, false, '/^\d+$/');
        check_input_parameter('date_creation', $_POST, false, '/^\d\d\d\d-\d\d-\d\d( \d\d:\d\d:\d\d)?$/');

        $image_id_str = is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : '';

        // photo() may have already set these; fall back for direct-page access
        if ($this->adminPhotoBaseUrl === '') {
            $this->adminPhotoBaseUrl = ServiceLocator::get(UrlGenerator::class)->admin('photo-' . $image_id_str);
        }
        $admin_photo_base_url = $this->adminPhotoBaseUrl;

        if (!isset($page['image'])) {
            $page['image'] = ServiceLocator::get(ImageAdminService::class)->getImageInfos($image_id_str, true);
        }

        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE representative_picture_id = ' . (is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0) . '
;';
        $represented_albums = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'id');

        if (isset($_GET['delete'])) {
            check_pwg_token();
            ServiceLocator::get(ImageAdminService::class)->deleteElements([is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0], true);
            ServiceLocator::get(UserAdminService::class)->invalidateUserCache();

            if ($custom_context = UserService::get()->getEditContext(is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0)) {
                redirect(str_replace('list/1,2', $custom_context, UrlService::get()->makeIndexUrl(['list' => [1, 2]])));
            }
            redirect(UrlService::get()->makeIndexUrl());
        }

        if (isset($_GET['sync_metadata'])) {
            ServiceLocator::get(MetadataAdminService::class)->syncMetadata([is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0]);
            PageState::current()->addInfo(l10n('Metadata synchronized from file'));
        }

        if (isset($_POST['submit'])) {
            check_pwg_token();

            $data        = [];
            $data['id']  = is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0;
            $data['level'] = is_numeric($_POST['level'] ?? null) ? (int) $_POST['level'] : 0;

            foreach (['name', 'author', 'comment'] as $field) {
                $post_field  = $_POST[$field] ?? null;
                $data[$field] = Config::allowHtmlDescriptions() ? $post_field : strip_tags(is_scalar($post_field) ? (string) $post_field : '');
            }

            $data['date_creation'] = !empty($_POST['date_creation']) ? $_POST['date_creation'] : null;
            $data = EventDispatcher::dispatch('picture_modify_before_update', $data);

            Dml::singleUpdate(Tables::images(), $data, ['id' => $data['id']]);

            $tag_ids = [];
            if (!empty($_POST['tags'])) {
                $tags_post = $_POST['tags'];
                if (is_scalar($tags_post)) {
                    $tag_ids = ServiceLocator::get(TagAdminService::class)->getTagIds((string) $tags_post);
                } elseif (is_array($tags_post)) {
                    $tag_ids = ServiceLocator::get(TagAdminService::class)->getTagIds(array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $tags_post));
                }
            }
            ServiceLocator::get(TagAdminService::class)->setTags($tag_ids, is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0);

            if (!isset($_POST['associate'])) {
                $_POST['associate'] = [];
            }
            check_input_parameter('associate', $_POST, true, ValidationPattern::ID);
            ServiceLocator::get(CategoryAdminService::class)->moveImagesToCategories(
                [is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0],
                is_array($_POST['associate']) ? array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $_POST['associate']) : []
            );

            ServiceLocator::get(UserAdminService::class)->invalidateUserCache();

            if (!isset($_POST['represent'])) {
                $_POST['represent'] = [];
            }
            check_input_parameter('represent', $_POST, true, ValidationPattern::ID);

            $represented_albums_int = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $represented_albums);
            $represent_post_int     = is_array($_POST['represent']) ? array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $_POST['represent']) : [];

            $no_longer = array_diff($represented_albums_int, $represent_post_int);
            if (count($no_longer) > 0) {
                ServiceLocator::get(CategoryAdminService::class)->setRandomRepresentant(array_values($no_longer));
            }

            $new_thumbnail_for = array_diff($represent_post_int, $represented_albums_int);
            if (count($new_thumbnail_for) > 0) {
                ServiceLocator::get(CategoryRepository::class)->setRepresentativePicture(
                    array_map(fn ($v): int => (int) $v, $new_thumbnail_for),
                    is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0
                );
            }

            $represented_albums = is_array($_POST['represent']) ? array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $_POST['represent']) : [];
            $tpl->assign(['save_success' => l10n('Photo informations updated')]);
            pwg_activity('photo', is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0, 'edit');

            $page['image'] = ServiceLocator::get(ImageAdminService::class)->getImageInfos(is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : '', true);
        }

        $tag_selection = ServiceLocator::get(TagAdminService::class)->getTaglistFromRows(
            ServiceLocator::get(TagRepository::class)
                ->findTagsByImageId(is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0)
        );

        /** @var array<string, mixed> $row */
        $row = is_array($page['image']) ? $page['image'] : [];

        if (isset($data['date_creation'])) {
            $row['date_creation'] = $data['date_creation'];
        }

        $storage_category_id = null;
        if (!empty($row['storage_category_id'])) {
            $storage_category_id = $row['storage_category_id'];
        }

        $image_file = $row['file'];

        $tpl->setFilenames(['picture_modify' => 'picture_modify.tpl']);

        $admin_url_start = $admin_photo_base_url . '-properties';
        $src_image       = new SrcImage($row);

        if (in_array($row['rotation'], [1, 3])) {
            [$row['width'], $row['height']] = [$row['height'], $row['width']];
        }

        $tpl->assign([
            'tag_selection'      => $tag_selection,
            'U_DOWNLOAD'         => ServiceLocator::get(UrlGenerator::class)->actionDownload((int) (is_scalar($_GET['image_id'] ?? null) ? $_GET['image_id'] : 0), 'e', get_pwg_token()),
            'U_SYNC'             => $admin_url_start . '&amp;sync_metadata=1',
            'U_DELETE'           => $admin_url_start . '&amp;delete=1&amp;pwg_token=' . get_pwg_token(),
            'U_HISTORY'          => ServiceLocator::get(UrlGenerator::class)->admin('history') . '&amp;filter_image_id=' . (is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : ''),
            'U_ACTIVITY'         => ServiceLocator::get(UrlGenerator::class)->admin('user_activity') . '&photo=' . (is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : ''),
            'PATH'               => $row['path'],
            'TN_SRC'             => DerivativeImage::url(IMG_MEDIUM, $src_image),
            'FILE_SRC'           => DerivativeImage::url(IMG_LARGE, $src_image),
            'NAME'               => isset($_POST['name']) ? stripslashes(is_scalar($_POST['name']) ? (string) $_POST['name'] : '') : ($row['name'] ?? null),
            'TITLE'              => render_element_name($row),
            'DIMENSIONS'         => (is_scalar($row['width'] ?? null) ? (string) $row['width'] : '') . ' * ' . (is_scalar($row['height'] ?? null) ? (string) $row['height'] : ''),
            'FORMAT'             => ((is_numeric($row['width'] ?? null) ? (int) $row['width'] : 0) >= (is_numeric($row['height'] ?? null) ? (int) $row['height'] : 0)) ? 1 : 0,
            'FILESIZE'           => (is_scalar($row['filesize'] ?? null) ? (string) $row['filesize'] : '') . ' KB',
            'REGISTRATION_DATE'  => format_date(is_string($row['date_available'] ?? null) ? $row['date_available'] : null),
            'AUTHOR'             => htmlspecialchars(isset($_POST['author']) ? stripslashes(is_scalar($_POST['author']) ? (string) $_POST['author'] : '') : (empty($row['author']) ? '' : (is_scalar($row['author']) ? (string) $row['author'] : ''))),
            'DATE_CREATION'      => $row['date_creation'],
            'DESCRIPTION'        => htmlspecialchars(isset($_POST['comment']) ? stripslashes(is_scalar($_POST['comment']) ? (string) $_POST['comment'] : '') : (empty($row['comment']) ? '' : (is_scalar($row['comment']) ? (string) $row['comment'] : ''))),
            'F_ACTION'           => ServiceLocator::get(UrlGenerator::class)->admin() . UrlService::get()->getQueryStringDiff(['sync_metadata']),
        ]);

        $added_by  = 'N/A';
        $userFields = Config::userFields();
        $foundUsername = ServiceLocator::get(UserRepository::class)->findUsernameById(
            $userFields['id'],
            $userFields['username'],
            Tables::users(),
            is_numeric($row['added_by'] ?? null) ? (int) $row['added_by'] : 0
        );
        if ($foundUsername !== null) {
            $row['added_by'] = $foundUsername;
        }

        $extTab     = explode('.', is_scalar($row['file'] ?? null) ? (string) $row['file'] : '');
        $intro_vars = [
            'file'    => l10n('%s', $row['file']),
            'date'    => l10n('Posted the %s', format_date(is_string($row['date_available'] ?? null) ? $row['date_available'] : null, ['day', 'month', 'year'])),
            'age'     => l10n(ucfirst(time_since(is_string($row['date_available'] ?? null) ? $row['date_available'] : null, 'year'))),
            'added_by' => l10n('Added by %s', $row['added_by']),
            'size'    => l10n('%s pixels, %.2f MB', (is_scalar($row['width'] ?? null) ? (string) $row['width'] : '') . '&times;' . (is_scalar($row['height'] ?? null) ? (string) $row['height'] : ''), (is_numeric($row['filesize'] ?? null) ? (float) $row['filesize'] : 0.0) / 1024),
            'stats'   => l10n('Visited %d times', $row['hit']),
            'id'      => l10n(is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''),
            'ext'     => l10n('%s file type', strtoupper(end($extTab))),
            'is_svg'  => (strtoupper(end($extTab)) == 'SVG'),
        ];

        if (Config::rateEnabled() && !empty($row['rating_score'])) {
            $row['nb_rates'] = ServiceLocator::get(RateRepository::class)
                ->countByElementId(is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0);
            $intro_vars['stats'] .= ', ' . sprintf(l10n('Rated %d times, score : %.2f'), (int) $row['nb_rates'], is_numeric($row['rating_score']) ? (float) $row['rating_score'] : 0.0);
        }

        $formats = DbConnection::get()->executeQuery('SELECT * FROM ' . Tables::imageFormat() . ' WHERE image_id = ' . (is_scalar($row['id'] ?? null) ? (string) $row['id'] : '0') . ';')->fetchAllAssociative();
        if (!empty($formats)) {
            $format_strings = [];
            foreach ($formats as $format) {
                $format_strings[] = sprintf('%s (%.2fMB)', is_scalar($format['ext']) ? (string) $format['ext'] : '', (is_numeric($format['filesize']) ? (int) $format['filesize'] : 0) / 1024);
            }
            $intro_vars['formats'] = l10n('Formats: %s', implode(', ', $format_strings));
        }

        $tpl->assign('INTRO', $intro_vars);

        if (in_array(get_extension(is_scalar($row['path'] ?? null) ? (string) $row['path'] : ''), Config::pictureExtensions())) {
            $tpl->assign('U_COI', ServiceLocator::get(UrlGenerator::class)->admin('picture_coi') . '&amp;image_id=' . (is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : ''));
        }

        $selected_level = $_POST['level'] ?? $row['level'];
        $tpl->assign(['level_options' => get_privacy_level_options(), 'level_options_selected' => [$selected_level]]);

        $related_categories     = [];
        $related_categories_ids = [];
        foreach (ServiceLocator::get(CategoryRepository::class)
            ->findCategoryInfosByImageId(is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0) as $catRow) {
            $name = get_cat_display_name_cache(is_scalar($catRow['uppercats'] ?? null) ? (string) $catRow['uppercats'] : '', ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-');
            if ($catRow['category_id'] == $storage_category_id) {
                $tpl->assign('STORAGE_CATEGORY', $name);
            }
            $related_categories[is_scalar($catRow['category_id'] ?? null) ? (string) $catRow['category_id'] : ''] = ['name' => $name, 'unlinkable' => $catRow['category_id'] != $storage_category_id];
            $related_categories_ids[] = $catRow['category_id'];
        }
        $tpl->assign('related_categories', $related_categories);
        $tpl->assign('related_categories_ids', $related_categories_ids);

        $userLevel  = is_numeric($user['level'] ?? null) ? (int) $user['level'] : 0;
        $imageLevel = is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0;
        if ($custom_context = UserService::get()->getEditContext(is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0)) {
            $tpl->assign('U_JUMPTO', UrlService::get()->makePictureUrl(['image_id' => is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : '']) . '/' . $custom_context);
        } elseif ($userLevel >= $imageLevel) {
            $authorizeds = array_diff(
                array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column(DbConnection::get()->executeQuery('SELECT category_id FROM ' . Tables::imageCategory() . ' WHERE image_id = ' . (is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0) . ';')->fetchAllAssociative(), 'category_id')),
                explode(',', PermissionService::get()->calculatePermissions(is_numeric($user['id']) ? (int) $user['id'] : 0, is_string($user['status']) ? $user['status'] : ''))
            );
            if (count($authorizeds) > 0) {
                $category = $authorizeds[array_rand($authorizeds)];
                $catNames = RequestCache::remember('cat_names', 'all', static fn (): array => array_column(DbConnection::get()->executeQuery('SELECT id, name, permalink FROM ' . Tables::categories() . ';')->fetchAllAssociative(), null, 'id') ?: []);
                $tpl->assign('U_JUMPTO', UrlService::get()->makePictureUrl([
                    'image_id'   => $_GET['image_id'],
                    'image_file' => $image_file,
                    'category'   => is_array($catNames) ? ($catNames[$category] ?? null) : null,
                ]));
            }
        }

        $associated_albums = array_column(DbConnection::get()->executeQuery('SELECT id FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id = category_id WHERE image_id = ' . (is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0) . ';')->fetchAllAssociative(), 'id');

        $cache_keys = ServiceLocator::get(AdminService::class)->getAdminClientCacheKeys(['tags', 'categories']);
        $tpl->assign([
            'associated_albums'             => $associated_albums,
            'represented_albums'            => $represented_albums,
            'STORAGE_ALBUM'                 => $storage_category_id,
            'CACHE_KEYS'                    => $cache_keys,
            'picture_modify_page_data_json' => json_encode([
                'CACHE_KEYS'            => $cache_keys,
                'ROOT_URL'              => UrlService::getRootUrl(),
                'associated_albums'     => $associated_albums,
                'str_create'            => l10n('Create'),
                'str_assoc_album_ab'    => l10n('Associate to album'),
                'related_categories_ids' => $related_categories_ids,
                'str_orphan'            => l10n('This photo is an orphan'),
                'str_are_you_sure'      => l10n('Are you sure?'),
                'str_yes'               => l10n('Yes, delete'),
                'str_no'                => l10n('No, I have changed my mind'),
                'str_cancel'            => l10n('Cancel'),
                'url_delete'            => $admin_url_start . '&delete=1&pwg_token=' . get_pwg_token(),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
            'PWG_TOKEN' => get_pwg_token(),
        ]);

        EventDispatcher::notify('loc_end_picture_modify');
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'picture_modify');
    }

    // ── picture_coi ───────────────────────────────────────────────────────────

    private function pictureCoi(): void
    {
        $tpl = TemplateRegistry::current();

        check_input_parameter('image_id', $_GET, false, ValidationPattern::ID);

        $imageIdCoi = is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : '0';
        $imgRepo    = ServiceLocator::get(ImageRepository::class);

        if (isset($_POST['submit'])) {
            $lRaw = $_POST['l'] ?? null;
            if (strlen(is_scalar($lRaw) ? (string) $lRaw : '') == 0) {
                $imgRepo->updateCoi((int) $imageIdCoi, null);
            } else {
                $coi = fraction_to_char(is_numeric($lRaw) ? (float) $lRaw : 0)
                    . fraction_to_char(is_numeric($_POST['t'] ?? null) ? (float) $_POST['t'] : 0)
                    . fraction_to_char(is_numeric($_POST['r'] ?? null) ? (float) $_POST['r'] : 0)
                    . fraction_to_char(is_numeric($_POST['b'] ?? null) ? (float) $_POST['b'] : 0);
                $imgRepo->updateCoi((int) $imageIdCoi, $coi);
            }
        }

        $image_infos = $imgRepo->findById((int) $imageIdCoi);
        if ($image_infos === null) {
            page_not_found('The requested image does not exist');
            return;
        }

        $row = $image_infos;

        if (isset($_POST['submit'])) {
            foreach (ImageStdParams::getDefinedTypeMap() as $params) {
                if ($params->sizing->max_crop != 0) {
                    ServiceLocator::get(ImageAdminService::class)->deleteElementDerivatives($row, $params->type);
                }
            }
            ServiceLocator::get(ImageAdminService::class)->deleteElementDerivatives($row, IMG_CUSTOM);
            $uid = '&b=' . time();
            if (Config::derivativeUrlStyle() == 1) {
                Config::override('derivative_url_style', 0);
            }
        } else {
            $uid = '';
        }

        $tpl_var = [
            'TITLE' => render_element_name($row),
            'ALT'   => $row['file'],
            'U_IMG' => DerivativeImage::url(IMG_LARGE, $row),
        ];

        if (!empty($row['coi'])) {
            $coi = is_scalar($row['coi']) ? (string) $row['coi'] : '';
            $tpl_var['coi'] = [
                'l' => char_to_fraction($coi[0]),
                't' => char_to_fraction($coi[1]),
                'r' => char_to_fraction($coi[2]),
                'b' => char_to_fraction($coi[3]),
            ];
        }

        foreach (ImageStdParams::getDefinedTypeMap() as $params) {
            if ($params->sizing->max_crop != 0) {
                $derivative = new DerivativeImage($params, new SrcImage($row));
                $tpl->append('cropped_derivatives', [
                    'U_IMG'    => (is_string($u = $derivative->getUrl()) ? $u : '') . $uid,
                    'HTM_SIZE' => $derivative->getSizeHtm(),
                ]);
            }
        }

        $tpl->assign($tpl_var);
        $tpl->setFilename('picture_coi', 'picture_coi.tpl');
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'picture_coi');
    }

    // ── picture_formats ───────────────────────────────────────────────────────

    private function pictureFormats(): void
    {
        $tpl = TemplateRegistry::current();

        check_input_parameter('image_id', $_GET, false, ValidationPattern::ID);
        $picFmtId = is_scalar($_GET['image_id'] ?? null) ? (string) $_GET['image_id'] : '0';

        $images  = DbConnection::get()->executeQuery('SELECT * FROM ' . Tables::images() . ' WHERE id = ' . $picFmtId . ';')->fetchAllAssociative();
        $image   = $images[0];
        $formats = DbConnection::get()->executeQuery('SELECT * FROM ' . Tables::imageFormat() . ' WHERE image_id = ' . $picFmtId . ';')->fetchAllAssociative();

        /** @var array<string, mixed> $lang */
        $lang = is_array($GLOBALS['lang']) ? $GLOBALS['lang'] : [];

        foreach ($formats as &$format) {
            $format['download_url'] = ServiceLocator::get(UrlGenerator::class)->actionFormat((int) (is_scalar($format['format_id']) ? $format['format_id'] : 0));
            $format['label']        = strtoupper(is_scalar($format['ext']) ? (string) $format['ext'] : '');
            $lang_key = 'format ' . strtoupper(is_scalar($format['ext']) ? (string) $format['ext'] : '');
            if (isset($lang[$lang_key]) && is_string($lang[$lang_key])) {
                $format['label'] = $lang[$lang_key];
            }
            $format['filesize'] = round((is_numeric($format['filesize']) ? (float) $format['filesize'] : 0.0) / 1024, 2);
        }
        unset($format);

        $tpl->assign([
            'ADD_FORMATS_URL' => ServiceLocator::get(UrlGenerator::class)->admin('photos_add') . '&formats=' . $picFmtId,
            'IMG_SQUARE_SRC'  => DerivativeImage::url(ImageStdParams::getByType(IMG_SQUARE), $image),
            'FORMATS'         => $formats,
            'PWG_TOKEN'       => get_pwg_token(),
            'page_data_json'  => json_encode([
                'pwg_token'                 => get_pwg_token(),
                'nb_formats'                => count($formats),
                'str_confirm_delete_format' => l10n('Delete %s format ?'),
                'str_confirm_msg'           => l10n('Yes, I am sure'),
                'str_cancel_msg'            => l10n('No, I have changed my mind'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tpl->setFilename('picture_formats', 'picture_formats.tpl');
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'picture_formats');
    }

    // ── photos_add ────────────────────────────────────────────────────────────

    private function photosAdd(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        defined('PHOTOS_ADD_BASE_URL') or define('PHOTOS_ADD_BASE_URL', ServiceLocator::get(UrlGenerator::class)->admin('photos_add'));

        $upload_form_config = ServiceLocator::get(UploadService::class)->getUploadFormConfig();
        $GLOBALS['upload_form_config'] = $upload_form_config;

        if (isset($_GET['section'])) {
            $page['tab'] = is_string($_GET['section']) ? $_GET['section'] : 'direct';
            if ($page['tab'] === 'ploader') {
                $page['tab'] = 'applications';
            }
        } else {
            $page['tab'] = 'direct';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('photos_add');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        $tpl->setFilenames(['photos_add' => 'photos_add_' . $page['tab'] . '.tpl']);

        $tab = (string) $page['tab'];
        if ($tab === 'direct') {
            $this->photosAddDirect();
        } elseif ($tab === 'ftp') {
            $this->photosAddFtp();
        } elseif ($tab === 'applications') {
            $this->photosAddApplications();
        }
    }

    // ── photos_add_direct ─────────────────────────────────────────────────────

    private function photosAddDirect(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];

        defined('PHOTOS_ADD_BASE_URL') or define('PHOTOS_ADD_BASE_URL', ServiceLocator::get(UrlGenerator::class)->admin('photos_add'));

        if (isset($_GET['batch'])) {
            check_input_parameter('batch', $_GET, false, '/^\d+(,\d+)*$/');
            ServiceLocator::get(ImageRepository::class)->deleteUserCaddie(is_numeric($user['id']) ? (int) $user['id'] : 0);
            $inserts = [];
            foreach (array_unique(explode(',', is_scalar($_GET['batch']) ? (string) $_GET['batch'] : '')) as $image_id) {
                $inserts[] = ['user_id' => $user['id'], 'element_id' => $image_id];
            }
            Dml::massInserts(Tables::caddie(), array_keys($inserts[0]), $inserts);
            redirect(ServiceLocator::get(UrlGenerator::class)->admin('batch_manager') . '&filter=prefilter-caddie');
        }

        if (PreferencesService::get()->userprefsGetParam('promote-mobile-apps', true)) {
            $register_date = ServiceLocator::get(UserRepository::class)->findEarliestRegistrationDate();
            $nb_cats       = ServiceLocator::get(CategoryRepository::class)->countAll();
            $nb_images     = ServiceLocator::get(ImageRepository::class)->countAll();
            $uagent_obj    = new \uagent_info();
            $tpl->assign('PROMOTE_MOBILE_APPS', (!$uagent_obj->DetectIos() && strtotime((string) $register_date) < strtotime('2 weeks ago') && $nb_cats >= 3 && $nb_images >= 30));
        } else {
            $tpl->assign('PROMOTE_MOBILE_APPS', false);
        }

        $tpl->assign('PHPWG_URL', PHPWG_URL);

        $display_formats       = Config::isFormatsEnabled() && isset($_GET['formats']);
        $have_formats_original = false;
        $formats_original_info = [];
        $formats_ext_info      = null;

        if ($display_formats && $_GET['formats']) {
            check_input_parameter('formats', $_GET, false, ValidationPattern::ID, false);
            $formatsId             = is_scalar($_GET['formats']) ? (string) $_GET['formats'] : '';
            $formats_original_info = ServiceLocator::get(ImageAdminService::class)->getImageInfos($formatsId);
            if ($formats_original_info) {
                $src_image = new SrcImage($formats_original_info);
                $formats_original_info['src'] = DerivativeImage::url(IMG_SQUARE, $src_image);
                $fmtId  = is_scalar($formats_original_info['id'] ?? null) ? (string) $formats_original_info['id'] : '0';
                $fmtRow = DbConnection::get()->executeQuery('SELECT * FROM ' . Tables::imageFormat() . ' WHERE image_id = ' . $fmtId . ';')->fetchAllAssociative();
                if (!empty($fmtRow)) {
                    $format_strings = [];
                    $formats_exts   = [];
                    foreach ($fmtRow as $fmt) {
                        $format_strings[] = sprintf('%s (%.2fMB)', is_scalar($fmt['ext']) ? (string) $fmt['ext'] : '', (is_numeric($fmt['filesize']) ? (int) $fmt['filesize'] : 0) / 1024);
                        $formats_exts[]   = strtolower(is_scalar($fmt['ext']) ? (string) $fmt['ext'] : '');
                    }
                    $formats_original_info['formats'] = l10n('Formats: %s', implode(', ', $format_strings));
                    $formats_ext_info                 = json_encode($formats_exts);
                }
                $extTab = explode('.', is_scalar($formats_original_info['file'] ?? null) ? (string) $formats_original_info['file'] : '');
                $formats_original_info['ext']    = l10n('%s file type', strtoupper(end($extTab)));
                $formats_original_info['u_edit'] = ServiceLocator::get(UrlGenerator::class)->admin('photo-' . $fmtId);
                $have_formats_original           = true;
            } else {
                PageState::current()->addError(l10n('The original picture selected dosen\'t exists.'));
            }
        }

        $nb_albums        = 0;
        $selected_category = [];
        ServiceLocator::get(DirectPreparer::class)->prepare(PHOTOS_ADD_BASE_URL);

        EventDispatcher::notify('loc_end_photo_add_direct');

        $unique_exts_for_json = array_unique(array_map(strtolower(...), Config::uploadFormAllTypes() ? Config::fileExtensions() : Config::pictureExtensions()));

        $tpl->assign([
            'ENABLE_FORMATS'        => Config::isFormatsEnabled(),
            'DISPLAY_FORMATS'       => $display_formats,
            'HAVE_FORMATS_ORIGINAL' => $have_formats_original,
            'FORMATS_ORIGINAL_INFO' => $formats_original_info,
            'FORMATS_EXT_INFO'      => $formats_ext_info,
            'SWITCH_FORMAT_MODE_URL' => ServiceLocator::get(UrlGenerator::class)->admin('photos_add') . ($display_formats ? '' : '&formats'),
            'format_ext'            => implode(',', Config::formatExtensions()),
            'str_format_ext'        => implode(', ', Config::formatExtensions()),
            'page_data_json'        => json_encode([
                'pwg_token'               => get_pwg_token(),
                'chunk_size'              => Config::uploadFormChunkSize() . 'kb',
                'max_file_size'           => Config::uploadFormMaxFileSize() . 'mb',
                'albumSummary_label'      => l10n('Album "%s" now contains %d photos'),
                'batch_Label'             => l10n('Manage this set of %d photos'),
                'file_ext'                => implode(',', $unique_exts_for_json),
                'formatMode'              => $display_formats,
                'format_ext'              => implode(',', Config::formatExtensions()),
                'format_remove'           => l10n('Remove'),
                'format_update_warning'   => l10n('This format already exists, it will be overwritten !'),
                'formatsAdded_label'      => l10n('%d formats added for %d photos'),
                'formatsUpdated_label'    => l10n('%d formats updated for %d photos'),
                'haveFormatsOriginal'     => $have_formats_original,
                'imageFormatsExtensions'  => $formats_ext_info ?? '',
                'nb_albums'               => $nb_albums,
                'originalImageId'         => $have_formats_original ? (is_numeric($formats_original_info['id'] ?? null) ? (int) $formats_original_info['id'] : -1) : -1,
                'photosAdded_label'       => l10n('%d photos uploaded'),
                'photosUpdated_label'     => l10n('%d photos updated'),
                'related_categories_ids'  => $selected_category,
                'str_and_X_others'        => l10n('and %d more'),
                'str_drop_album_ab'       => l10n('Drop into album'),
                'str_format_warning'      => l10n('Error when trying to detect formats'),
                'str_format_warning_multiple' => l10n('There is multiple image in the database with the following names : %s.'),
                'str_format_warning_notFound' => l10n('No picture found with the following name : %s.'),
                'str_upload_in_progress'  => l10n('Upload in progress'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'photos_add');
    }

    // ── photos_add_ftp ────────────────────────────────────────────────────────

    private function photosAddFtp(): void
    {
        $tpl = TemplateRegistry::current();

        defined('PHOTOS_ADD_BASE_URL') or define('PHOTOS_ADD_BASE_URL', ServiceLocator::get(UrlGenerator::class)->admin('photos_add'));

        $tpl->assign('FTP_HELP_CONTENT', load_language('help/photos_add_ftp.html', '', ['return' => true]));
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Upload Photos'));
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'photos_add');
    }

    // ── photos_add_applications ───────────────────────────────────────────────

    private function photosAddApplications(): void
    {
        $tpl = TemplateRegistry::current();

        defined('PHOTOS_ADD_BASE_URL') or define('PHOTOS_ADD_BASE_URL', ServiceLocator::get(UrlGenerator::class)->admin('photos_add'));

        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Upload Photos'));
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'photos_add');
    }
}
