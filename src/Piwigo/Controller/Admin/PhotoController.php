<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
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
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeEncoding;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\LangService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Rate\RateRepository;
use Piwigo\Tag\TagRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

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
    /** @var array<string,mixed>|null */
    private ?array $imageInfo = null;

    public function __construct(
        private readonly Connection $conn,
        private readonly AdminService $adminService,
        private readonly CategoryAdminService $categoryAdminService,
        private readonly CategoryRepository $categoryRepository,
        private readonly DateService $dateService,
        private readonly DirectPreparer $directPreparer,
        private readonly HtmlService $htmlService,
        private readonly ImageAdminService $imageAdminService,
        private readonly ImageRepository $imageRepository,
        private readonly LangService $langService,
        private readonly MetadataAdminService $metadataAdminService,
        private readonly PermissionService $permissionService,
        private readonly PreferencesService $preferencesService,
        private readonly RateRepository $rateRepository,
        private readonly StringUtil $stringUtil,
        private readonly TagAdminService $tagAdminService,
        private readonly TagRepository $tagRepository,
        private readonly UploadService $uploadService,
        private readonly UrlGenerator $urlGenerator,
        private readonly UrlService $urlService,
        private readonly UserAdminService $userAdminService,
        private readonly UserRepository $userRepository,
        private readonly UserService $userService,
        private readonly Util $util,
    ) {
    }

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
        $this->util->checkInputParameter('cat_id', $_GET, false, ValidationPattern::ID);
        $this->util->checkInputParameter('image_id', $_GET, false, ValidationPattern::ID);

        $rawImageIdStr = $_GET['image_id'] ?? null;
        $image_id_str = is_string($rawImageIdStr) ? $rawImageIdStr : '';
        $this->adminPhotoBaseUrl = $this->urlGenerator->admin('photo-' . $image_id_str);
        $GLOBALS['admin_photo_base_url'] = $this->adminPhotoBaseUrl;

        $this->imageInfo = $this->imageAdminService->getImageInfos($image_id_str, true);

        if (isset($_GET['cat_id'])) {
            $GLOBALS['category'] = $this->categoryRepository
                ->findCategoryById(is_scalar($_GET['cat_id']) ? (int) $_GET['cat_id'] : 0);
        }

        $rawTab = $_GET['tab'] ?? null;
        $tab    = is_string($rawTab) ? $rawTab : 'properties';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('photo');
        $tabsheet->select($tab);
        $tabsheet->assign();

        $tpl->assign([
            'ADMIN_PAGE_TITLE' => new Html(Lang::t('Edit photo') . ' <span class="image-id">#' . htmlspecialchars($image_id_str) . '</span>'),
        ]);
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
        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;
        $this->util->checkInputParameter('image_id', $_GET, false, ValidationPattern::ID);
        $this->util->checkInputParameter('level', $_POST, false, '/^\d+$/');
        $this->util->checkInputParameter('date_creation', $_POST, false, '/^\d\d\d\d-\d\d-\d\d( \d\d:\d\d:\d\d)?$/');

        $rawImageIdStr2 = $_GET['image_id'] ?? null;
        $image_id_str = is_string($rawImageIdStr2) ? $rawImageIdStr2 : '';

        // photo() may have already set these; fall back for direct-page access
        if ($this->adminPhotoBaseUrl === '') {
            $this->adminPhotoBaseUrl = $this->urlGenerator->admin('photo-' . $image_id_str);
        }
        $admin_photo_base_url = $this->adminPhotoBaseUrl;

        if (!isset($this->imageInfo)) {
            $this->imageInfo = $this->imageAdminService->getImageInfos($image_id_str, true);
        }

        $getImageId = $_GET['image_id'] ?? null;
        $getImageIdInt = is_numeric($getImageId) ? (int) $getImageId : 0;
        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE representative_picture_id = ' . $getImageIdInt . '
;';
        $represented_albums = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id');

        if (isset($_GET['delete'])) {
            $this->util->checkPwgToken();
            $this->imageAdminService->deleteElements([$getImageIdInt], true);
            $this->userAdminService->invalidateUserCache();

            if (($custom_context = $this->userService->getEditContext($getImageIdInt)) !== false && $custom_context !== null && $custom_context !== '') {
                $this->util->redirect(str_replace('list/1,2', $custom_context, $this->urlService->makeIndexUrl(['list' => [1, 2]])));
            }
            $this->util->redirect($this->urlService->makeIndexUrl());
        }

        if (isset($_GET['sync_metadata'])) {
            $this->metadataAdminService->syncMetadata([$getImageIdInt]);
            PageState::current()->addInfo(Lang::t('Metadata synchronized from file'));
        }

        if (isset($_POST['submit'])) {
            $this->util->checkPwgToken();

            $data        = [];
            $data['id']  = $getImageIdInt;
            $postLevelRaw = $_POST['level'] ?? null;
            $data['level'] = is_numeric($postLevelRaw) ? (int) $postLevelRaw : 0;

            foreach (['name', 'author', 'comment'] as $field) {
                $post_field  = $_POST[$field] ?? null;
                $data[$field] = Config::allowHtmlDescriptions() ? $post_field : strip_tags(is_string($post_field) ? $post_field : '');
            }

            $rawDateCreation = $_POST['date_creation'] ?? null;
            $data['date_creation'] = (is_string($rawDateCreation) && $rawDateCreation !== '') ? $rawDateCreation : null;
            $data = EventDispatcher::dispatch('picture_modify_before_update', $data);

            Dml::singleUpdate(Tables::images(), $data, ['id' => $data['id']]);

            $tag_ids = [];
            $tags_post = $_POST['tags'] ?? null;
            if ($tags_post !== null && $tags_post !== '') {
                if (is_array($tags_post)) {
                    $tag_ids = $this->tagAdminService->getTagIds(array_map(fn (mixed $v): string => is_string($v) ? $v : '', $tags_post));
                } else {
                    /** @phpstan-ignore-next-line argument.type */
                    $tag_ids = $this->tagAdminService->getTagIds($tags_post);
                }
            }
            $this->tagAdminService->setTags($tag_ids, $getImageIdInt);

            if (!isset($_POST['associate'])) {
                $_POST['associate'] = [];
            }
            $this->util->checkInputParameter('associate', $_POST, true, ValidationPattern::ID);
            $this->categoryAdminService->moveImagesToCategories(
                [$getImageIdInt],
                is_array($_POST['associate']) ? array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $_POST['associate']) : []
            );

            $this->userAdminService->invalidateUserCache();

            if (!isset($_POST['represent'])) {
                $_POST['represent'] = [];
            }
            $this->util->checkInputParameter('represent', $_POST, true, ValidationPattern::ID);

            $represented_albums_int = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $represented_albums);
            $represent_post_int     = is_array($_POST['represent']) ? array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $_POST['represent']) : [];

            $no_longer = array_diff($represented_albums_int, $represent_post_int);
            if (count($no_longer) > 0) {
                $this->categoryAdminService->setRandomRepresentant(array_values($no_longer));
            }

            $new_thumbnail_for = array_diff($represent_post_int, $represented_albums_int);
            if (count($new_thumbnail_for) > 0) {
                $this->categoryRepository->setRepresentativePicture(
                    $new_thumbnail_for,
                    $getImageIdInt
                );
            }

            $represented_albums = is_array($_POST['represent']) ? array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $_POST['represent']) : [];
            $tpl->assign(['save_success' => Lang::t('Photo informations updated')]);
            $this->util->pwgActivity('photo', $getImageIdInt, 'edit');

            $rawImageId3 = $_GET['image_id'] ?? null;
            $this->imageInfo = $this->imageAdminService->getImageInfos(is_string($rawImageId3) ? $rawImageId3 : '', true);
        }

        $tag_selection = $this->tagAdminService->getTaglistFromRows(
            $this->tagRepository
                ->findTagsByImageId($getImageIdInt)
        );

        /** @var array<string, mixed> $row */
        $row = is_array($this->imageInfo) ? $this->imageInfo : [];

        if (isset($data['date_creation'])) {
            $row['date_creation'] = $data['date_creation'];
        }

        $storage_category_id = null;
        if (!empty($row['storage_category_id'])) {
            $storage_category_id = $row['storage_category_id'];
        }

        $image_file = $row['file'];

        $admin_url_start = $admin_photo_base_url . '-properties';
        $src_image       = new SrcImage($row);

        if (in_array($row['rotation'], [1, 3])) {
            [$row['width'], $row['height']] = [$row['height'], $row['width']];
        }

        $tpl->assign([
            'tag_selection'      => $tag_selection,
            'U_DOWNLOAD'         => $this->urlGenerator->actionDownload(is_numeric($image_id_str) ? (int) $image_id_str : 0, 'e', $this->util->getPwgToken()),
            'U_SYNC'             => $admin_url_start . '&sync_metadata=1',
            'U_DELETE'           => $admin_url_start . '&delete=1&pwg_token=' . $this->util->getPwgToken(),
            'U_HISTORY'          => $this->urlGenerator->admin('history') . '&filter_image_id=' . $image_id_str,
            'U_ACTIVITY'         => $this->urlGenerator->admin('user_activity') . '&photo=' . $image_id_str,
            'PATH'               => $row['path'],
            'TN_SRC'             => DerivativeImage::url(DerivativeSize::Medium->value, $src_image),
            'FILE_SRC'           => DerivativeImage::url(DerivativeSize::Large->value, $src_image),
            'NAME'               => isset($_POST['name']) ? stripslashes(is_string($rawPostName = $_POST['name']) ? $rawPostName : '') : ($row['name'] ?? null),
            'TITLE'              => $this->htmlService->renderElementName($row),
            'DIMENSIONS'         => (is_scalar($row['width'] ?? null) ? (string) $row['width'] : '') . ' * ' . (is_scalar($row['height'] ?? null) ? (string) $row['height'] : ''),
            'FORMAT'             => ((is_numeric($row['width'] ?? null) ? (int) $row['width'] : 0) >= (is_numeric($row['height'] ?? null) ? (int) $row['height'] : 0)) ? 1 : 0,
            'FILESIZE'           => (is_scalar($row['filesize'] ?? null) ? (string) $row['filesize'] : '') . ' KB',
            'REGISTRATION_DATE'  => $this->dateService->formatDate(is_string($row['date_available'] ?? null) ? $row['date_available'] : null),
            'AUTHOR'             => htmlspecialchars(isset($_POST['author']) ? stripslashes(is_string($rawPostAuthor = $_POST['author']) ? $rawPostAuthor : '') : (empty($row['author']) ? '' : (is_string($row['author']) ? $row['author'] : ''))),
            'DATE_CREATION'      => $row['date_creation'],
            'DESCRIPTION'        => htmlspecialchars(isset($_POST['comment']) ? stripslashes(is_string($rawPostComment = $_POST['comment']) ? $rawPostComment : '') : (empty($row['comment']) ? '' : (is_string($row['comment']) ? $row['comment'] : ''))),
            'F_ACTION'           => $this->urlGenerator->admin() . $this->urlService->getQueryStringDiff(['sync_metadata']),
        ]);

        $added_by  = 'N/A';
        $userFields = Config::userFields();
        $foundUsername = $this->userRepository->findUsernameById(
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
            'file'    => Lang::t('%s', is_string($row['file'] ?? null) ? $row['file'] : ''),
            'date'    => Lang::t('Posted the %s', $this->dateService->formatDate(is_string($row['date_available'] ?? null) ? $row['date_available'] : null, ['day', 'month', 'year'])),
            'age'     => Lang::t(ucfirst($this->dateService->timeSince(is_string($row['date_available'] ?? null) ? $row['date_available'] : null, 'year'))),
            'added_by' => Lang::t('Added by %s', is_string($row['added_by'] ?? null) ? $row['added_by'] : ''),
            'size'    => new Html(Lang::t('%s pixels, %.2f MB', (is_scalar($row['width'] ?? null) ? (string) $row['width'] : '') . '&times;' . (is_scalar($row['height'] ?? null) ? (string) $row['height'] : ''), (is_numeric($row['filesize'] ?? null) ? (float) $row['filesize'] : 0.0) / 1024.0)),
            'stats'   => Lang::t('Visited %d times', is_numeric($row['hit'] ?? null) ? (int) $row['hit'] : 0),
            'id'      => Lang::t(is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''),
            'ext'     => Lang::t('%s file type', strtoupper(end($extTab))),
            'is_svg'  => (strtoupper(end($extTab)) == 'SVG'),
        ];

        if (Config::rateEnabled() && !empty($row['rating_score'])) {
            $row['nb_rates'] = $this->rateRepository
                ->countByElementId($getImageIdInt);
            $intro_vars['stats'] .= ', ' . sprintf(Lang::t('Rated %d times, score : %.2f'), $row['nb_rates'], is_numeric($row['rating_score']) ? (float) $row['rating_score'] : 0.0);
        }

        $formats = $this->conn->executeQuery('SELECT * FROM ' . Tables::imageFormat() . ' WHERE image_id = ' . (is_scalar($row['id'] ?? null) ? (string) $row['id'] : '0') . ';')->fetchAllAssociative();
        if (!empty($formats)) {
            $format_strings = [];
            foreach ($formats as $format) {
                $format_strings[] = sprintf('%s (%.2fMB)', is_string($format['ext'] ?? null) ? $format['ext'] : '', (is_numeric($format['filesize']) ? (int) $format['filesize'] : 0) / 1024);
            }
            $intro_vars['formats'] = Lang::t('Formats: %s', implode(', ', $format_strings));
        }

        $tpl->assign('INTRO', $intro_vars);

        if (in_array($this->stringUtil->getExtension(is_scalar($row['path'] ?? null) ? (string) $row['path'] : ''), Config::pictureExtensions())) {
            $tpl->assign('U_COI', $this->urlGenerator->admin('picture_coi') . '&image_id=' . $image_id_str);
        }

        $selected_level = $_POST['level'] ?? $row['level'];
        $tpl->assign(['level_options' => $this->util->getPrivacyLevelOptions(), 'level_options_selected' => [$selected_level]]);

        $related_categories     = [];
        $related_categories_ids = [];
        foreach ($this->categoryRepository
            ->findCategoryInfosByImageId($getImageIdInt) as $catRow) {
            $name = $this->htmlService->getCatDisplayNameCache(is_scalar($catRow['uppercats'] ?? null) ? (string) $catRow['uppercats'] : '', $this->urlGenerator->admin() . '&page=album-');
            $nameHtml = new Html($name);
            if ($catRow['category_id'] == $storage_category_id) {
                $tpl->assign('STORAGE_CATEGORY', $nameHtml);
            }
            $related_categories[is_scalar($catRow['category_id'] ?? null) ? (string) $catRow['category_id'] : ''] = ['name' => $nameHtml, 'unlinkable' => $catRow['category_id'] != $storage_category_id];
            $related_categories_ids[] = $catRow['category_id'];
        }
        $tpl->assign('related_categories', $related_categories);
        $tpl->assign('related_categories_ids', $related_categories_ids);

        $userLevel  = is_numeric($user['level'] ?? null) ? (int) $user['level'] : 0;
        $imageLevel = is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0;
        if (($custom_context = $this->userService->getEditContext($getImageIdInt)) !== false && $custom_context !== null && $custom_context !== '') {
            $tpl->assign('U_JUMPTO', $this->urlService->makePictureUrl(['image_id' => $_GET['image_id'] ?? '']) . '/' . $custom_context);
        } elseif ($userLevel >= $imageLevel) {
            $authorizeds = array_diff(
                array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($this->conn->executeQuery('SELECT category_id FROM ' . Tables::imageCategory() . ' WHERE image_id = ' . ($getImageIdInt) . ';')->fetchAllAssociative(), 'category_id')),
                explode(',', $this->permissionService->calculatePermissions(is_numeric($user['id']) ? (int) $user['id'] : 0, is_string($user['status']) ? $user['status'] : ''))
            );
            if (count($authorizeds) > 0) {
                $category = $authorizeds[array_rand($authorizeds)];
                $catNames = RequestCache::remember('cat_names', 'all', fn (): array => array_column($this->conn->executeQuery('SELECT id, name, permalink FROM ' . Tables::categories() . ';')->fetchAllAssociative(), null, 'id') ?: []);
                $tpl->assign('U_JUMPTO', $this->urlService->makePictureUrl([
                    'image_id'   => $_GET['image_id'],
                    'image_file' => $image_file,
                    'category'   => is_array($catNames) ? ($catNames[$category] ?? null) : null,
                ]));
            }
        }

        $associated_albums = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id = category_id WHERE image_id = ' . ($getImageIdInt) . ';')->fetchAllAssociative(), 'id');

        $cache_keys = $this->adminService->getAdminClientCacheKeys(['tags', 'categories']);
        $tpl->assign([
            'associated_albums'             => $associated_albums,
            'represented_albums'            => $represented_albums,
            'STORAGE_ALBUM'                 => $storage_category_id,
            'CACHE_KEYS'                    => $cache_keys,
            'picture_modify_page_data_json' => json_encode([
                'CACHE_KEYS'            => $cache_keys,
                'ROOT_URL'              => UrlService::getRootUrl(),
                'associated_albums'     => $associated_albums,
                'str_create'            => Lang::t('Create'),
                'str_assoc_album_ab'    => Lang::t('Associate to album'),
                'related_categories_ids' => $related_categories_ids,
                'str_orphan'            => Lang::t('This photo is an orphan'),
                'str_are_you_sure'      => Lang::t('Are you sure?'),
                'str_yes'               => Lang::t('Yes, delete'),
                'str_no'                => Lang::t('No, I have changed my mind'),
                'str_cancel'            => Lang::t('Cancel'),
                'url_delete'            => $admin_url_start . '&delete=1&pwg_token=' . $this->util->getPwgToken(),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
            'PWG_TOKEN' => $this->util->getPwgToken(),
        ]);

        EventDispatcher::notify('loc_end_picture_modify');
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'picture_modify.latte');
    }

    // ── picture_coi ───────────────────────────────────────────────────────────

    private function pictureCoi(): void
    {
        $tpl = TemplateRegistry::current();

        $this->util->checkInputParameter('image_id', $_GET, false, ValidationPattern::ID);

        $rawImageIdCoi = $_GET['image_id'] ?? null;
        $imageIdCoi    = is_string($rawImageIdCoi) ? $rawImageIdCoi : '0';
        $imgRepo    = $this->imageRepository;

        if (isset($_POST['submit'])) {
            $lRaw = $_POST['l'] ?? null;
            if (strlen(is_string($lRaw) ? $lRaw : '') == 0) {
                $imgRepo->updateCoi((int) $imageIdCoi, null);
            } else {
                $tRaw = $_POST['t'] ?? null;
                $rRaw = $_POST['r'] ?? null;
                $bRaw = $_POST['b'] ?? null;
                $coi = DerivativeEncoding::fractionToChar(is_numeric($lRaw) ? (float) $lRaw : 0)
                    . DerivativeEncoding::fractionToChar(is_numeric($tRaw) ? (float) $tRaw : 0)
                    . DerivativeEncoding::fractionToChar(is_numeric($rRaw) ? (float) $rRaw : 0)
                    . DerivativeEncoding::fractionToChar(is_numeric($bRaw) ? (float) $bRaw : 0);
                $imgRepo->updateCoi((int) $imageIdCoi, $coi);
            }
        }

        $image_infos = $imgRepo->findById((int) $imageIdCoi);
        if ($image_infos === null) {
            $this->htmlService->pageNotFound('The requested image does not exist');
            return;
        }

        $row = $image_infos;

        if (isset($_POST['submit'])) {
            foreach (ImageStdParams::getDefinedTypeMap() as $params) {
                if ($params->sizing->max_crop != 0) {
                    $this->imageAdminService->deleteElementDerivatives($row, $params->type);
                }
            }
            $this->imageAdminService->deleteElementDerivatives($row, DerivativeSize::Custom->value);
            $uid = '&b=' . time();
            if (Config::derivativeUrlStyle() == 1) {
                Config::override('derivative_url_style', 0);
            }
        } else {
            $uid = '';
        }

        $tpl_var = [
            'TITLE' => $this->htmlService->renderElementName($row),
            'ALT'   => $row['file'],
            'U_IMG' => DerivativeImage::url(DerivativeSize::Large->value, $row),
        ];

        if (!empty($row['coi'])) {
            $coiRaw = $row['coi'];
            $coi = is_string($coiRaw) ? $coiRaw : '';
            if (strlen($coi) >= 4) {
                $tpl_var['coi'] = [
                    'l' => DerivativeEncoding::charToFraction(substr($coi, 0, 1)),
                    't' => DerivativeEncoding::charToFraction(substr($coi, 1, 1)),
                    'r' => DerivativeEncoding::charToFraction(substr($coi, 2, 1)),
                    'b' => DerivativeEncoding::charToFraction(substr($coi, 3, 1)),
                ];
            }
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
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'picture_coi.latte');
    }

    // ── picture_formats ───────────────────────────────────────────────────────

    private function pictureFormats(): void
    {
        $tpl = TemplateRegistry::current();

        $this->util->checkInputParameter('image_id', $_GET, false, ValidationPattern::ID);
        $rawPicFmtId = $_GET['image_id'] ?? null;
        $picFmtId = is_string($rawPicFmtId) ? $rawPicFmtId : '0';

        $images  = $this->conn->executeQuery('SELECT * FROM ' . Tables::images() . ' WHERE id = ' . $picFmtId . ';')->fetchAllAssociative();
        $image   = $images[0];
        $formats = $this->conn->executeQuery('SELECT * FROM ' . Tables::imageFormat() . ' WHERE image_id = ' . $picFmtId . ';')->fetchAllAssociative();

        foreach ($formats as &$format) {
            $format['download_url'] = $this->urlGenerator->actionFormat((int) (is_scalar($format['format_id']) ? $format['format_id'] : 0));
            $extRaw = $format['ext'] ?? null;
            $extStr = is_string($extRaw) ? $extRaw : '';
            $format['label']        = strtoupper($extStr);
            $lang_key = 'format ' . strtoupper($extStr);
            if (Lang::has($lang_key)) {
                $format['label'] = Lang::t($lang_key);
            }
            $format['filesize'] = round((is_numeric($format['filesize']) ? (float) $format['filesize'] : 0.0) / 1024.0, 2);
        }
        unset($format);

        $tpl->assign([
            'ADD_FORMATS_URL' => $this->urlGenerator->admin('photos_add') . '&formats=' . $picFmtId,
            'IMG_SQUARE_SRC'  => DerivativeImage::url(ImageStdParams::getByType(DerivativeSize::Square->value), $image),
            'FORMATS'         => $formats,
            'PWG_TOKEN'       => $this->util->getPwgToken(),
            'page_data_json'  => json_encode([
                'pwg_token'                 => $this->util->getPwgToken(),
                'nb_formats'                => count($formats),
                'str_confirm_delete_format' => Lang::t('Delete %s format ?'),
                'str_confirm_msg'           => Lang::t('Yes, I am sure'),
                'str_cancel_msg'            => Lang::t('No, I have changed my mind'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'picture_formats.latte');
    }

    // ── photos_add ────────────────────────────────────────────────────────────

    private function photosAdd(): void
    {
        $tpl = TemplateRegistry::current();

        defined('PHOTOS_ADD_BASE_URL') or define('PHOTOS_ADD_BASE_URL', $this->urlGenerator->admin('photos_add'));

        $upload_form_config = $this->uploadService->getUploadFormConfig();
        $GLOBALS['upload_form_config'] = $upload_form_config;

        $rawSection = $_GET['section'] ?? null;
        $tab = is_string($rawSection) ? $rawSection : 'direct';
        if ($tab === 'ploader') {
            $tab = 'applications';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('photos_add');
        $tabsheet->select($tab);
        $tabsheet->assign();
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
        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        defined('PHOTOS_ADD_BASE_URL') or define('PHOTOS_ADD_BASE_URL', $this->urlGenerator->admin('photos_add'));

        if (isset($_GET['batch'])) {
            $this->util->checkInputParameter('batch', $_GET, false, '/^\d+(,\d+)*$/');
            $this->imageRepository->deleteUserCaddie(is_numeric($user['id']) ? (int) $user['id'] : 0);
            $inserts = [];
            foreach (array_unique(explode(',', is_string($rawGetBatch = $_GET['batch']) ? $rawGetBatch : '')) as $image_id) {
                $inserts[] = ['user_id' => $user['id'], 'element_id' => $image_id];
            }
            Dml::massInserts(Tables::caddie(), array_keys($inserts[0]), $inserts);
            $this->util->redirect($this->urlGenerator->admin('batch_manager') . '&filter=prefilter-caddie');
        }

        if ($this->preferencesService->userprefsGetParam('promote-mobile-apps', true)) {
            $register_date = $this->userRepository->findEarliestRegistrationDate();
            $nb_cats       = $this->categoryRepository->countAll();
            $nb_images     = $this->imageRepository->countAll();
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

        $rawFormatsIdVal = $_GET['formats'] ?? null;
        $formatsId       = is_string($rawFormatsIdVal) ? $rawFormatsIdVal : '';
        if ($display_formats && $formatsId !== '') {
            $this->util->checkInputParameter('formats', $_GET, false, ValidationPattern::ID, false);
            $formats_original_info = $this->imageAdminService->getImageInfos($formatsId);
            if ($formats_original_info !== null && count($formats_original_info) > 0) {
                $src_image = new SrcImage($formats_original_info);
                $formats_original_info['src'] = DerivativeImage::url(DerivativeSize::Square->value, $src_image);
                $fmtIdRaw = $formats_original_info['id'] ?? null;
                $fmtId  = is_scalar($fmtIdRaw) ? (string) $fmtIdRaw : '0';
                $fmtRow = $this->conn->executeQuery('SELECT * FROM ' . Tables::imageFormat() . ' WHERE image_id = ' . $fmtId . ';')->fetchAllAssociative();
                if (!empty($fmtRow)) {
                    $format_strings = [];
                    $formats_exts   = [];
                    foreach ($fmtRow as $fmt) {
                        $fmtExtRaw = $fmt['ext'] ?? null;
                        $format_strings[] = sprintf('%s (%.2fMB)', is_string($fmtExtRaw) ? $fmtExtRaw : '', (is_numeric($fmt['filesize']) ? (int) $fmt['filesize'] : 0) / 1024);
                        $formats_exts[]   = strtolower(is_string($fmtExtRaw) ? $fmtExtRaw : '');
                    }
                    $formats_original_info['formats'] = Lang::t('Formats: %s', implode(', ', $format_strings));
                    $formats_ext_info                 = json_encode($formats_exts);
                }
                $fmtFileRaw = $formats_original_info['file'] ?? null;
                $extTab = explode('.', is_scalar($fmtFileRaw) ? (string) $fmtFileRaw : '');
                $formats_original_info['ext']    = Lang::t('%s file type', strtoupper(end($extTab)));
                $formats_original_info['u_edit'] = $this->urlGenerator->admin('photo-' . $fmtId);
                $have_formats_original           = true;
            } else {
                PageState::current()->addError(Lang::t('The original picture selected dosen\'t exists.'));
            }
        }

        $nb_albums        = 0;
        $selected_category = [];
        $this->directPreparer->prepare(PHOTOS_ADD_BASE_URL);

        EventDispatcher::notify('loc_end_photo_add_direct');

        $unique_exts_for_json = array_unique(array_map(strtolower(...), Config::uploadFormAllTypes() ? Config::fileExtensions() : Config::pictureExtensions()));

        $tpl->assign([
            'ENABLE_FORMATS'        => Config::isFormatsEnabled(),
            'DISPLAY_FORMATS'       => $display_formats,
            'HAVE_FORMATS_ORIGINAL' => $have_formats_original,
            'FORMATS_ORIGINAL_INFO' => $formats_original_info,
            'FORMATS_EXT_INFO'      => $formats_ext_info,
            'SWITCH_FORMAT_MODE_URL' => $this->urlGenerator->admin('photos_add') . ($display_formats ? '' : '&formats'),
            'format_ext'            => implode(',', Config::formatExtensions()),
            'str_format_ext'        => implode(', ', Config::formatExtensions()),
            'page_data_json'        => json_encode([
                'pwg_token'               => $this->util->getPwgToken(),
                'chunk_size'              => Config::uploadFormChunkSize() . 'kb',
                'max_file_size'           => Config::uploadFormMaxFileSize() . 'mb',
                'albumSummary_label'      => Lang::t('Album "%s" now contains %d photos'),
                'batch_Label'             => Lang::t('Manage this set of %d photos'),
                'file_ext'                => implode(',', $unique_exts_for_json),
                'formatMode'              => $display_formats,
                'format_ext'              => implode(',', Config::formatExtensions()),
                'format_remove'           => Lang::t('Remove'),
                'format_update_warning'   => Lang::t('This format already exists, it will be overwritten !'),
                'formatsAdded_label'      => Lang::t('%d formats added for %d photos'),
                'formatsUpdated_label'    => Lang::t('%d formats updated for %d photos'),
                'haveFormatsOriginal'     => $have_formats_original,
                'imageFormatsExtensions'  => $formats_ext_info ?? '',
                'nb_albums'               => $nb_albums,
                /** @phpstan-ignore-next-line notIdentical.alwaysTrue */
                'originalImageId'         => $have_formats_original && $formats_original_info !== null && isset($formats_original_info['id']) && is_numeric($formats_original_info['id']) ? (int) $formats_original_info['id'] : -1,
                'photosAdded_label'       => Lang::t('%d photos uploaded'),
                'photosUpdated_label'     => Lang::t('%d photos updated'),
                'related_categories_ids'  => $selected_category,
                'str_and_X_others'        => Lang::t('and %d more'),
                'str_drop_album_ab'       => Lang::t('Drop into album'),
                'str_format_warning'      => Lang::t('Error when trying to detect formats'),
                'str_format_warning_multiple' => Lang::t('There is multiple image in the database with the following names : %s.'),
                'str_format_warning_notFound' => Lang::t('No picture found with the following name : %s.'),
                'str_upload_in_progress'  => Lang::t('Upload in progress'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'photos_add_direct.latte');
    }

    // ── photos_add_ftp ────────────────────────────────────────────────────────

    private function photosAddFtp(): void
    {
        $tpl = TemplateRegistry::current();

        defined('PHOTOS_ADD_BASE_URL') or define('PHOTOS_ADD_BASE_URL', $this->urlGenerator->admin('photos_add'));

        $ftpHelp = $this->langService->loadLanguage('help/photos_add_ftp.html', '', ['return' => true]);
        $tpl->assign('FTP_HELP_CONTENT', new Html(is_string($ftpHelp) ? $ftpHelp : ''));
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Upload Photos'));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'photos_add_ftp.latte');
    }

    // ── photos_add_applications ───────────────────────────────────────────────

    private function photosAddApplications(): void
    {
        $tpl = TemplateRegistry::current();

        defined('PHOTOS_ADD_BASE_URL') or define('PHOTOS_ADD_BASE_URL', $this->urlGenerator->admin('photos_add'));

        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Upload Photos'));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'photos_add_applications.latte');
    }
}
