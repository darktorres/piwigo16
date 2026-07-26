<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * Ported from admin/picture_modify.php (the "properties" tab of the "photo"
 * page slug, dispatched by PhotoSubController). PhotoSubController threads
 * $adminPhotoBaseUrl through render() directly (Legacy Coupling Retirement
 * Phase 8, 8g -- formerly a `global $admin_photo_base_url;` read).
 *
 * P23 batch 6d fix: the sync_metadata action was reachable via a plain GET
 * with no check_pwg_token() (unlike the delete/submit actions in this same
 * file, which both already had one) and its own template link (U_SYNC)
 * carried no token either -- a real CSRF gap, closed here the same way
 * U_DELETE already protects itself one line below it.
 */
final class PictureModifyPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    private static function userService(): UserService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::userService();
    }

    private static function tagService(): TagService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::tagService();
    }

    /**
     * DRY extraction (Phase 1k DI-chain audit): the same PermissionService
     * recipe was repeated verbatim at 3 sites in this file.
     */
    private static function permissionService(): PermissionService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::permissionService();
    }

    public function render(string $adminPhotoBaseUrl): void
    {
        // Phase 2 global-residual sweep: $page is a local scratch array
        // for this method's own body only (no longer `global $page;`),
        // same shape as Section\SectionPopulator::populate()'s own
        // equivalent fix (Track A5.2e).
        /** @var array<string, mixed> $page */
        $page = [];
        $template = \Piwigo\Template\CurrentTemplate::get();

        $conn = DbConnection::build();
        $imageService = new ImageService(new ImageRepository($conn), \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService());
        $htmlRenderer = \Piwigo\Bootstrap\PresentationAccessor::htmlService();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        new \Piwigo\Validation\InputValidator()
            ->validate('image_id', $_GET, false, ValidationPattern::ID);
        new \Piwigo\Validation\InputValidator()
            ->validate('level', $_POST, false, '/^\d+$/');
        new \Piwigo\Validation\InputValidator()
            ->validate('date_creation', $_POST, false, '/^\d\d\d\d-\d\d-\d\d( \d\d:\d\d:\d\d)?$/');

        // check_input_parameter() only validates the raw $_GET value against
        // ValidationPattern::ID (or dies); it does not narrow $_GET's type for PHPStan, so
        // re-derive a real int here for every later use.
        $image_id = 0;
        if (isset($_GET['image_id']) && is_numeric($_GET['image_id'])) {
            $image_id = (int) $_GET['image_id'];
        }

        // retrieving direct information about picture. This may have been
        // already done by PhotoSubController but this renderer can also be
        // reached directly.
        if (! isset($page['image'])) {
            $page['image'] = $imageService->getImageInfos($image_id, $htmlRenderer, true);
        }

        // represent
        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE representative_picture_id = ' . $image_id . '
;';
        $represented_albums_raw = array_column($conn->fetchAllAssociative($query), 'id');
        $represented_albums = [];
        foreach ($represented_albums_raw as $represented_album_value) {
            if (is_numeric($represented_album_value)) {
                $represented_albums[] = (int) $represented_album_value;
            }
        }

        // +-------------------------------------------------------------------+
        // |                             delete photo                          |
        // +-------------------------------------------------------------------+

        if (isset($_GET['delete'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

            $imageService->deleteElements([$image_id], $this->urlService, true);
            PermissionCacheInvalidator::invalidate();

            // where to redirect the user now?
            //
            // 1. if a category is available in the URL, use it
            // 2. else use the first reachable linked category
            // 3. redirect to gallery root

            if ((bool) ($custom_context = self::userService()->getEditContext($image_id))) {
                // considering we have a context available, we fake one to build the url
                // and we replace it with the context found in the session for this image_id
                $this->redirectService->redirect(str_replace('list/1,2', $custom_context, $this->urlService->makeIndexUrl([
                    'list' => [1, 2],
                ])));
            }

            $this->redirectService->redirect($this->urlService->makeIndexUrl());
        }

        // +-------------------------------------------------------------------+
        // |                          synchronize metadata                     |
        // +-------------------------------------------------------------------+

        if (isset($_GET['sync_metadata'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

            \Piwigo\Bootstrap\ExtendedDomainAccessor::metadataService()
                ->syncMetadata([$image_id]);
            \Piwigo\Core\PageState::current()->addInfo(Lang::t('Metadata synchronized from file'));
        }

        // --------------------------------------------------------- update informations
        /** @var array<string, mixed> $data */
        $data = [];
        if (isset($_POST['submit'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

            $data = [];
            $data['id'] = $image_id;
            $data['level'] = $_POST['level'];

            $to_sanitize_fields = ['name', 'author', 'comment'];
            foreach ($to_sanitize_fields as $field) {
                $raw_field_value = $_POST[$field] ?? null;
                $field_value = is_string($raw_field_value) ? $raw_field_value : '';
                $data[$field] = (\Piwigo\Config\CurrentConfig::allowHtmlDescriptions()) ? $field_value : strip_tags($field_value);
            }

            if (! in_array($_POST['date_creation'] ?? null, [null, false, 0, '0', '', []], true)) {
                $data['date_creation'] = $_POST['date_creation'];
            } else {
                $data['date_creation'] = null;
            }

            $pre_hook_data = $data;
            $data = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('picture_modify_before_update', $data);
            if (! is_array($data)) {
                // 'picture_modify_before_update' handlers are documented to filter
                // the array<string, mixed> $data they receive and return the same
                // shape, but trigger_change()'s own return type is mixed (a handler
                // could misbehave) -- fall back to the pre-hook data rather than
                // silently dropping the admin's edit.
                $data = $pre_hook_data;
            }

            /** @var array<string, mixed> $data */
            new BatchWriter($conn)
                ->singleUpdate(
                    Tables::images(),
                    $data,
                    [
                        'id' => $image_id,
                    ]
                );

            // time to deal with tags
            $tagService = self::tagService();

            $tag_ids = [];
            $raw_tags_post = $_POST['tags'] ?? null;
            if (! in_array($raw_tags_post, [null, false, 0, '0', '', []], true)) {
                if (is_array($raw_tags_post)) {
                    $raw_tags_post_strings = [];
                    foreach ($raw_tags_post as $raw_tag) {
                        if (is_string($raw_tag)) {
                            $raw_tags_post_strings[] = $raw_tag;
                        }
                    }
                    $tag_ids = $tagService->getTagIds($raw_tags_post_strings);
                } elseif (is_string($raw_tags_post)) {
                    $tag_ids = $tagService->getTagIds($raw_tags_post);
                }
            }
            $tagService->setTags($tag_ids, $image_id);

            // association to albums
            if (! isset($_POST['associate'])) {
                $_POST['associate'] = [];
            }
            new \Piwigo\Validation\InputValidator()
                ->validate('associate', $_POST, true, ValidationPattern::ID);

            $associate_categories = [];
            if (is_array($_POST['associate'])) {
                foreach ($_POST['associate'] as $associate_value) {
                    if (is_numeric($associate_value)) {
                        $associate_categories[] = (int) $associate_value;
                    }
                }
            }
            $imageService->moveImagesToCategories([$image_id], $associate_categories);

            PermissionCacheInvalidator::invalidate();

            // thumbnail for albums
            if (! isset($_POST['represent'])) {
                $_POST['represent'] = [];
            }
            new \Piwigo\Validation\InputValidator()
                ->validate('represent', $_POST, true, ValidationPattern::ID);

            $represent_categories = [];
            if (is_array($_POST['represent'])) {
                foreach ($_POST['represent'] as $represent_value) {
                    if (is_numeric($represent_value)) {
                        $represent_categories[] = (int) $represent_value;
                    }
                }
            }

            $no_longer_thumbnail_for = array_diff($represented_albums, $represent_categories);
            if (count($no_longer_thumbnail_for) > 0) {
                new CategoryService(
                    new CategoryRepository($conn),
                    self::permissionService()
                )->setRandomRepresentant($no_longer_thumbnail_for);
            }

            $new_thumbnail_for = array_diff($represent_categories, $represented_albums);
            if (count($new_thumbnail_for) > 0) {
                $query = '
UPDATE ' . Tables::categories() . '
  SET representative_picture_id = ' . $image_id . '
  WHERE id IN (' . implode(',', $new_thumbnail_for) . ')
;';
                $conn->executeStatement($query);
            }

            $represented_albums = $represent_categories;

            $template->assign(
                [
                    'save_success' => Lang::t('Photo informations updated'),
                ]
            );

            \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService()
                ->record('photo', $image_id, 'edit');

            // refresh page cache
            $page['image'] = $imageService->getImageInfos($image_id, $htmlRenderer, true);
        }

        // tags
        $query = '
SELECT
    id,
    name
  FROM ' . Tables::imageTag() . ' AS it
    JOIN ' . Tables::tags() . ' AS t ON t.id = it.tag_id
  WHERE image_id = ' . $image_id . '
;';
        $tag_selection = self::tagService()
            ->getTagList($query, $htmlRenderer);

        // getImageInfos($image_id, $htmlRenderer, true) fatal_errors (never returns) when the
        // photo doesn't exist, so $page['image'] is guaranteed to be a real
        // array<string, mixed> row by this point.
        /** @var array<string, mixed> $row */
        $row = $page['image'];

        if (isset($data['date_creation'])) {
            $row['date_creation'] = $data['date_creation'];
        }

        $storage_category_id = null;
        if (is_numeric($row['storage_category_id']) && (int) $row['storage_category_id'] !== 0) {
            $raw_storage_category_id = $row['storage_category_id'];
            $storage_category_id = (is_int($raw_storage_category_id) || is_string($raw_storage_category_id)) ? (string) $raw_storage_category_id : null;
        }

        $image_file = $row['file'];

        // +-------------------------------------------------------------------+
        // |                             template init                         |
        // +-------------------------------------------------------------------+

        $template->set_filenames(
            [
                'picture_modify' => 'picture_modify.tpl',
            ]
        );

        $admin_url_start = $adminPhotoBaseUrl . '-properties';

        $src_image = new SrcImage($row);

        // in case the photo needs a rotation of 90 degrees (clockwise or counterclockwise), we switch width and height
        if (in_array(is_numeric($row['rotation']) ? (int) $row['rotation'] : null, [1, 3], true)) {
            [$row['width'], $row['height']] = [$row['height'], $row['width']];
        }

        // $_POST['name']/['author']/['comment'] are mixed (raw superglobal reads);
        // re-derive real strings here, falling back to the stored row value when the
        // field wasn't (validly) resubmitted.
        $post_name = $_POST['name'] ?? null;
        $name_value = is_string($post_name) ? stripslashes($post_name) : (is_string($row['name'] ?? null) ? $row['name'] : '');

        $post_author = $_POST['author'] ?? null;
        $author_value = is_string($post_author) ? stripslashes($post_author) : (is_string($row['author'] ?? null) && $row['author'] !== '' ? $row['author'] : '');

        $post_comment = $_POST['comment'] ?? null;
        $comment_value = is_string($post_comment) ? stripslashes($post_comment) : (is_string($row['comment'] ?? null) && $row['comment'] !== '' ? $row['comment'] : '');

        $template->assign(
            [
                'tag_selection' => $tag_selection,
                'U_DOWNLOAD' => 'action.php?id=' . $image_id . '&amp;part=e&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken(),
                'U_SYNC' => $admin_url_start . '&amp;sync_metadata=1&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken(),
                'U_DELETE' => $admin_url_start . '&amp;delete=1&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken(),
                'U_HISTORY' => $this->urlService->getRootUrl() . 'admin.php?page=history&amp;filter_image_id=' . $image_id,
                'U_ACTIVITY' => $this->urlService->getRootUrl() . 'admin.php?page=user_activity&photo=' . $image_id,

                'PATH' => $row['path'],

                'TN_SRC' => DerivativeImage::url(ImageStdParams::MEDIUM, $src_image),
                'FILE_SRC' => DerivativeImage::url(ImageStdParams::LARGE, $src_image),

                'NAME' => $name_value,

                'TITLE' => $htmlRenderer->renderElementName($row),

                'DIMENSIONS' => (is_scalar($row['width']) ? (string) $row['width'] : '') . ' * ' . (is_scalar($row['height']) ? (string) $row['height'] : ''),

                'FORMAT' => ($row['width'] >= $row['height']) ? 1 : 0, // 0:horizontal, 1:vertical

                'FILESIZE' => (is_scalar($row['filesize']) ? (string) $row['filesize'] : '') . ' KB',

                'REGISTRATION_DATE' => \Piwigo\Core\DateHelper::formatDate(is_string($row['date_available']) || is_int($row['date_available']) ? $row['date_available'] : false),

                'AUTHOR' => htmlspecialchars($author_value),

                'DATE_CREATION' => $row['date_creation'],

                'DESCRIPTION' => htmlspecialchars($comment_value),

                'F_ACTION' => $this->urlService->getRootUrl() . 'admin.php'
                    . $this->urlService->getQueryStringDiff(['sync_metadata']),
            ]
        );

        $added_by = 'N/A';
        $user_fields = \Piwigo\Config\CurrentConfig::userFields();
        $uf_username = $user_fields['username'];
        $uf_id = $user_fields['id'];
        $row_added_by = is_numeric($row['added_by']) ? (int) $row['added_by'] : 0;
        $added_by_username = new UserRepository($conn)
            ->findUsernameById($row_added_by, $uf_id, $uf_username);
        if ($added_by_username !== null) {
            $row['added_by'] = $added_by_username;
        }

        $row_file = is_string($row['file']) ? $row['file'] : '';
        $extTab = explode('.', $row_file);

        $intro_vars = [
            'file' => Lang::t('%s', $row_file),
            'date' => Lang::t('Posted the %s', \Piwigo\Core\DateHelper::formatDate(is_string($row['date_available']) || is_int($row['date_available']) ? $row['date_available'] : false, ['day', 'month', 'year'])),
            'age' => Lang::t(ucfirst(\Piwigo\Core\DateHelper::timeSince(is_string($row['date_available']) || is_int($row['date_available']) ? $row['date_available'] : '', 'year'))),
            'added_by' => Lang::t('Added by %s', $row['added_by']),
            'size' => Lang::t('%s pixels, %.2f MB', (is_scalar($row['width']) ? (string) $row['width'] : '') . '&times;' . (is_scalar($row['height']) ? (string) $row['height'] : ''), (is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0) / 1024.0),
            'stats' => Lang::t('Visited %d times', $row['hit']),
            'id' => Lang::t(is_string($row['id']) ? $row['id'] : ''),
            'ext' => Lang::t('%s file type', strtoupper(end($extTab))),
            'is_svg' => (strtoupper(end($extTab)) === 'SVG'),
        ];

        if (\Piwigo\Config\CurrentConfig::rateEnabled() && ! in_array($row['rating_score'], [null, false, 0, 0.0, '0', '', []], true)) {
            $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::rate() . '
  WHERE element_id = ' . $image_id . '
;';
            $rate_row = $conn->fetchNumeric($query);
            // a COUNT(*) query always yields exactly one row; this guard is what
            // actually protects the list-destructure below (not assert(), which is
            // a no-op under this app's zend.assertions=-1).
            if ($rate_row !== false) {
                $row['nb_rates'] = $rate_row[0] ?? null;

                $intro_vars['stats'] .= ', ' . sprintf(Lang::t('Rated %d times, score : %.2f'), is_numeric($row['nb_rates']) ? (int) $row['nb_rates'] : 0, is_numeric($row['rating_score']) ? (float) $row['rating_score'] : 0.0);
            }
        }

        $formats = new ImageRepository($conn)
            ->findFormatsForImage($image_id);

        if ($formats !== []) {
            $format_strings = [];

            foreach ($formats as $format) {
                $format_strings[] = sprintf('%s (%.2fMB)', $format->ext, ((float) ($format->filesize ?? 0)) / 1024.0);
            }

            $intro_vars['formats'] = Lang::t('Formats: %s', implode(', ', $format_strings));
        }

        $template->assign('INTRO', $intro_vars);

        $row_path = is_string($row['path']) ? $row['path'] : null;
        $picture_ext = \Piwigo\Config\CurrentConfig::pictureExtensions();
        if (in_array(\Piwigo\Core\StringHelper::getExtension($row_path), $picture_ext, true)) {
            $template->assign('U_COI', $this->urlService->getRootUrl() . 'admin.php?page=picture_coi&amp;image_id=' . $image_id);
        }

        // image level options
        $selected_level = $_POST['level'] ?? $row['level'];
        $template->assign(
            [
                'level_options' => \Piwigo\Permission\PermissionService::getPrivacyLevelOptions(),
                'level_options_selected' => [$selected_level],
            ]
        );

        // categories
        $query = '
SELECT category_id, uppercats, dir
  FROM ' . Tables::imageCategory() . ' AS ic
    INNER JOIN ' . Tables::categories() . ' AS c
      ON c.id = ic.category_id
  WHERE image_id = ' . $image_id . '
;';
        $related_categories = [];
        $related_categories_ids = [];

        foreach ($conn->fetchAllAssociative($query) as $cat_row) {
            $raw_row_category_id = $cat_row['category_id'];
            $row_category_id = (is_int($raw_row_category_id) || is_string($raw_row_category_id)) ? (string) $raw_row_category_id : '';
            $row_uppercats = is_string($cat_row['uppercats']) ? $cat_row['uppercats'] : '';

            $name =
              $htmlRenderer->getCatDisplayNameCache(
                  $row_uppercats,
                  $this->urlService->getRootUrl() . 'admin.php?page=album-'
              );

            if ($row_category_id === $storage_category_id) {
                $template->assign('STORAGE_CATEGORY', $name);
            }

            $related_categories[$row_category_id] = [
                'name' => $name,
                'unlinkable' => $row_category_id !== $storage_category_id,
            ];
            $related_categories_ids[] = $row_category_id;
        }

        $template->assign('related_categories', $related_categories);
        $template->assign('related_categories_ids', $related_categories_ids);

        // jump to link
        //
        // 1. if an edit_context is available, we use it (without checking permissions)
        // 2. else if user level is higher than image level, randomly find an authorized category
        // 3. else no jumpto link

        // re-derived from $page['image'] rather than $row: $row still holds the
        // image row at this point, but its 'level' value may be stale after the
        // POST-handling branch above already reassigned $page['image'].
        $image_level = 0;
        if (is_array($page['image']) && is_numeric($page['image']['level'] ?? null)) {
            $image_level = (int) $page['image']['level'];
        }

        if ((bool) ($custom_context = self::userService()->getEditContext($image_id))) {
            $template->assign('U_JUMPTO', $this->urlService->makePictureUrl([
                'image_id' => $image_id,
            ]) . '/' . $custom_context);
        } elseif (\Piwigo\Users\CurrentUser::get()->level >= $image_level) {
            $query = '
SELECT category_id
  FROM ' . Tables::imageCategory() . '
  WHERE image_id = ' . $image_id . '
;';

            // array_column() over the fetched rows gives list<mixed> here since
            // only the 'category_id' column is selected; drop non-scalar rows then
            // stringify, since DBAL can hand back native ints for this column.
            $authorized_category_ids = array_map(
                strval(...),
                array_filter(
                    array_column($conn->fetchAllAssociative($query), 'category_id'),
                    static fn (mixed $v): bool => is_int($v) || is_string($v)
                )
            );

            $authorizeds = array_diff(
                $authorized_category_ids,
                explode(
                    ',',
                    new \Piwigo\Permission\ForbiddenCategoriesCache(self::permissionService(), \Piwigo\Cache\CachePools::permissions())
                        ->getForUser(\Piwigo\Users\CurrentUser::get()->id, \Piwigo\Users\CurrentUser::get()->status->value)
                )
            );

            if (count($authorizeds) > 0) {
                $authorizeds_values = array_values($authorizeds);
                $category = $authorizeds_values[random_int(0, count($authorizeds_values) - 1)];

                $cat_names_raw = \Piwigo\Core\ProcessCache::get('cat_names');
                $cat_names = is_array($cat_names_raw) ? $cat_names_raw : [];
                $url_img = $this->urlService->makePictureUrl(
                    [
                        'image_id' => $image_id,
                        'image_file' => $image_file,
                        'category' => $cat_names[$category] ?? null,
                    ]
                );

                $template->assign('U_JUMPTO', $url_img);
            }
        }

        // associate to albums
        $query = '
SELECT id
  FROM ' . Tables::categories() . '
    INNER JOIN ' . Tables::imageCategory() . ' ON id = category_id
  WHERE image_id = ' . $image_id . '
;';
        $associated_albums = array_column($conn->fetchAllAssociative($query), 'id');

        $template->assign([
            'associated_albums' => $associated_albums,
            'represented_albums' => $represented_albums,
            'STORAGE_ALBUM' => $storage_category_id,
            'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['tags', 'categories']),
            'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                ->getToken(),
        ]);

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_picture_modify');

        // ----------------------------------------------------------- sending html code
        $template->assign_var_from_handle('ADMIN_CONTENT', 'picture_modify');
    }
}
