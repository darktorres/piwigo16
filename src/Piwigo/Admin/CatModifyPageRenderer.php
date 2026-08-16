<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Projection\CatModifyPageContext;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\SqlDialect;
use Piwigo\Event\Location\LocBeginCatModify;
use Piwigo\Event\Location\LocEndCatModify;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Site\SiteEntity;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;

/**
 * Renders the "properties" tab of the "album" admin page (dispatched by
 * AlbumSubController).
 *
 * Access control is enforced by admin.php's dispatch gate
 * (check_status(AccessLevel::Administrator)) before this renderer runs, so
 * render() does not repeat that check.
 */
final class CatModifyPageRenderer
{
    /**
     * $category starts as AlbumSubController::handle()'s own
     * {@see \Piwigo\Category\Projection\Category::toArray()} result (same
     * as AlbumNotificationPageRenderer/CatPermPageRenderer's own $category)
     * -- but unlike those two siblings, this method both widens 2 of its
     * core fields' types (the "nullable fields" loop below turns
     * site_id/id_uppercat from ?int into int|string by substituting ''
     * for null) and adds 5 brand new keys (is_virtual/has_images/
     * nb_subcats/nb_images_recursive/cat_full_dir) -- genuinely outgrows
     * the Projection's own shape as this method progresses, same "risk of
     * untested retype" call as PictureModifyPageRenderer's own $row.
     *
     * @param array<string, mixed> $category
     */
    public function render(Lang $lang, UrlServiceInterface $urlService, array $category, EventDispatcher $eventDispatcher, PageState $pageState, CurrentUser $currentUser, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, CsrfService $csrfService, ActivityService $activityService, CategoryService $categoryService, HtmlRenderingInterface $htmlRenderer, EntityManagerInterface $entityManager): void
    {
        $template = $currentTemplate->get();

        $eventDispatcher->dispatch(new LocBeginCatModify());

        // 'id' is the categories table primary key (NOT NULL); AlbumSubController's
        // own fetchAssociative() call (one file over the include boundary PHPStan
        // can't see into) always returns it as numeric. Narrow once here and reuse
        // throughout the rest of this method's many uses of the category id.
        //
        // This replaces a real bug: the original `isset($_GET['cat_id']) &&
        // is_numeric(...)` check logged a fatal signal (ErrorCollector::
        // recordFatal(), see HtmlService::fatalError()'s own docblock for
        // why that never actually halts) on failure without a following
        // throw, so that check never actually halted anything. The very
        // next line then concatenated raw $_GET['cat_id']
        // directly into SQL with zero escaping -- a real, if
        // Administrator-gated, SQL injection shape. $category['id'] (this
        // method's own $category parameter, already the real DB row
        // AlbumSubController loaded via a validated cat_id) is the actual
        // source of truth for this same value, so this now derives
        // $category_id once, up front, and uses it everywhere below --
        // removing the redundant/broken $_GET re-read entirely rather
        // than replacing it with a new validating DTO for data this
        // method already had.
        $category_id = is_numeric($category['id']) ? (int) $category['id'] : 0;

        // --------------------------------------------------------- form criteria check

        // nullable fields
        foreach (['comment', 'dir', 'site_id', 'id_uppercat'] as $nullable) {
            /** @var array<string, mixed> $category */
            if (! isset($category[$nullable])) {
                $category[$nullable] = '';
            }
        }

        /** @var array<string, mixed> $category */
        $category['is_virtual'] = in_array($category['dir'], [null, false, 0, '0', '', []], true) ? true : false;

        $category['has_images'] = $categoryService->hasImages($category_id);

        // number of sub-categories
        $subcat_ids = $categoryService->getSubcatIds([$category_id]);

        $category['nb_subcats'] = count($subcat_ids) - 1;

        // Navigation path
        $category_uppercats = is_string($category['uppercats']) ? $category['uppercats'] : '';
        $navigation = $htmlRenderer->getCatDisplayNameCache(
            $category_uppercats,
            $urlService->getRootUrl() . 'admin.php?page=album-'
        );

        // Parent navigation path
        $uppercats_array = explode(',', $category_uppercats);
        if (count($uppercats_array) > 1) {
            array_pop($uppercats_array);
            $parent_navigation = $htmlRenderer->getCatDisplayNameCache(
                implode(',', $uppercats_array),
                $urlService->getRootUrl() . 'admin.php?page=album-'
            );
        } else {
            $parent_navigation = $lang->t('Root');
        }

        // ----------------------------------------------------- template initialization
        $base_url = $urlService->getRootUrl() . 'admin.php?page=';
        $cat_list_url = $base_url . 'albums';

        // 'id_uppercat' is one of the nullable fields normalized to '' above
        // (root category); otherwise it is the parent category id.
        //
        // $category comes from Category\Projection\Category::toArray()
        // (see this method's own top-of-file docblock), whose
        // 'id_uppercat' key is a real ?int, not a numeric string -- accept
        // int or string here, same pattern this file's own site_id
        // handling uses just below (representative_picture_id decision,
        // ~L299). $category_id_uppercat and PARENT_CAT_ID (below) feed
        // cat_modify.latte's own `var parent_album`/`related_categories_ids`
        // JS globals, which the move-album jstree widget
        // (themes/admin/default/js/cat_modify.js) uses to preselect the
        // album's current parent.
        $category_id_uppercat_raw = $category['id_uppercat'];
        $category_id_uppercat = (is_int($category_id_uppercat_raw) || is_string($category_id_uppercat_raw)) ? $category_id_uppercat_raw : '';

        $self_url = $cat_list_url;
        if ($category_id_uppercat !== '') {
            $self_url .= '&amp;parent_id=' . $category_id_uppercat;
        }

        // We show or hide this warning in JS
        $pageState->addWarning($lang->t('This album is currently locked, visible only to administrators.') . '<span class="icon-cone unlock-album">' . $lang->t('Unlock it') . '</span>');

        $categories_nav = (string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation));
        $categories_parent_nav = (string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $parent_navigation));
        $u_jumpto = $urlService->makeIndexUrl(
            [
                'category' => $category,
            ]
        );

        $cat_commentable = null;
        if ($currentConfig->activateComments) {
            $cat_commentable = SqlDialect::booleanToString((bool) $category['commentable']);
        }

        // manage album elements link
        $image_count = 0;
        $info_title = '';
        $u_manage_elements = null;
        if ($category['has_images']) {
            $u_manage_elements = $base_url . 'batch_manager&amp;filter=album-' . $category_id;

            $row = $categoryService->getPhotoCountAndDateRange($category_id);
            $image_count = $row->count;
            // date_available is a NOT NULL column but the driver still types every
            // fetched value as string|null; format_date()'s phpDoc param forbids
            // null, so fall back to false (its "no date" sentinel) if that ever
            // isn't the case.
            $min_date = $row->minDate ?? false;
            $max_date = $row->maxDate ?? false;

            if ($min_date === $max_date) {
                $info_title = $lang->t(
                    'This album contains %d photos, added on %s.',
                    $image_count,
                    DateHelper::formatDate($min_date)
                );
            } else {
                $info_title = $lang->t(
                    'This album contains %d photos, added between %s and %s.',
                    $image_count,
                    DateHelper::formatDate($min_date),
                    DateHelper::formatDate($max_date)
                );
            }

        }
        $info_photos = $lang->t('%d photos', $image_count);

        // total number of images under this category (including sub-categories)
        $image_ids_recursive = $categoryService->getDistinctImageIdsInCategories($subcat_ids);

        $category['nb_images_recursive'] = count($image_ids_recursive);

        // date creation
        $occured_on = $activityService
            ->getOccuredOnForObject($category_id, 'album', 'add');

        $info_creation_since = null;
        $info_creation = null;
        if ($occured_on !== null) {
            $info_creation_since = DateHelper::timeSince($occured_on, 'day', $format = null, $with_text = true, $with_week = true, $only_last_unit = true);
            $info_creation = DateHelper::formatDate($occured_on, ['day', 'month', 'year']);
        }

        // Sub Albums
        $nb_direct_sub = count($categoryService->getChildrenOfParent($category_id));

        $info_direct_sub = $lang->t(
            '%d sub-albums',
            $nb_direct_sub
        );

        // lastmodified is a NOT NULL DATETIME column, but the driver still types
        // every fetched value as string|null; narrow with real fallbacks (matching
        // the min_date/max_date/occured_on pattern above) rather than assuming.
        $category_lastmodified = is_string($category['lastmodified']) ? $category['lastmodified'] : null;
        $info_id = $lang->t('Numeric identifier : %d', $category_id);
        $info_last_modified_since = DateHelper::timeSince($category_lastmodified ?? '', 'minute', $format = null, $with_text = true, $with_week = true, $only_last_unit = true);
        $info_last_modified = DateHelper::formatDate($category_lastmodified ?? false, ['day', 'month', 'year']);
        $info_images_recursive = $lang->t(
            '%d including sub-albums',
            $category['nb_images_recursive']
        );
        $info_subcats = $lang->t(
            '%d in whole branch',
            $category['nb_subcats']
        );

        $u_manage_ranks = $base_url . 'element_set_ranks&amp;cat_id=' . $category_id;
        $cache_keys = AdminUiHelper::getAdminClientCacheKeys($urlService, ['categories']);

        $cat_full_dir = null;
        $cat_dir_name = null;
        $cat_min_dir = null;
        $u_sync = null;
        if (! (bool) $category['is_virtual']) {
            $category['cat_full_dir'] = $this->getCompleteDir($category_id, $categoryService, $entityManager);
            $category_full_dir = preg_replace('/\/$/', '', $category['cat_full_dir']);
            $cat_full_dir = $category_full_dir;
            $cat_dir_name = basename((string) $category_full_dir);
            $cat_min_dir = $this->getMinLocalDir($category_full_dir);

            if ($currentConfig->enableSynchronization) {
                $category_site_id = $category['site_id'];
                $category_site_id = (is_int($category_site_id) || is_string($category_site_id)) ? $category_site_id : '';
                $u_sync = $base_url . 'site_update&amp;site=' . $category_site_id . '&amp;cat_id=' . $category_id;
            }

        }

        // representant management
        // 'representative_picture_id' is a nullable FK column; DBAL's native
        // int/float casting (MYSQLI_OPT_INT_AND_FLOAT_NATIVE) means the
        // driver can hand back either a native int or a string depending on
        // which controller populated $GLOBALS['category'], so accept both
        // rather than assuming string|null.
        $category_representative_picture_id_raw = $category['representative_picture_id'];
        $category_representative_picture_id = (is_int($category_representative_picture_id_raw) || is_string($category_representative_picture_id_raw)) ? $category_representative_picture_id_raw : 0;
        $representant = null;
        if ($category['has_images'] or ! in_array($category_representative_picture_id, [0, '0', ''], true)) {
            $tpl_representant = [];

            // picture to display : the identified representant or the generic random
            // representant ?
            if (! in_array($category_representative_picture_id, [0, '0', ''], true)) {
                $tpl_representant['picture'] = $categoryService->getCategoryRepresentantProperties($category_representative_picture_id, $urlService, $entityManager, ImageStdParams::MEDIUM);
            }

            // can the admin choose to set a new random representant ?
            $tpl_representant['ALLOW_SET_RANDOM'] = ($category['has_images'] ? true : false);

            // can the admin delete the current representant ?
            // the outer `if` above already guarantees
            // !empty($category_representative_picture_id) whenever
            // !$category['has_images'], since that's the only way its own
            // has_images-or-!empty(...) condition could be true here.
            if (
                ($category['has_images']
                 and $currentConfig->allowRandomRepresentative)
                or ! $category['has_images']) {
                $tpl_representant['ALLOW_DELETE'] = true;
            }
            $representant = $tpl_representant;
        }

        $parent_category = null;
        if ((bool) $category['is_virtual']) {
            $parent_category = $category_id_uppercat === '' ? [] : [$category_id_uppercat];
        }

        $template->assignContext(new CatModifyPageContext(
            categoriesNav: $categories_nav,
            categoriesParentNav: $categories_parent_nav,
            parentCatId: $category_id_uppercat !== '' ? $category_id_uppercat : 0,
            catId: $category_id,
            catName: htmlspecialchars(is_string($category['name']) ? $category['name'] : ''),
            catComment: htmlspecialchars(is_string($category['comment']) ? $category['comment'] : ''),
            isVisible: SqlDialect::booleanToString((bool) $category['visible']),
            catAdminAccess: $categoryService->catAdminAccess($category_id, $currentUser),
            uDelete: $base_url . 'albums',
            uJumpto: $u_jumpto,
            uAddPhotosAlbum: $base_url . 'photos_add&amp;album=' . $category_id,
            uChildren: $cat_list_url . '&amp;parent_id=' . $category_id,
            uMove: $base_url . 'albums&amp;parent_id=' . $category_id,
            uActivity: $urlService->getRootUrl() . 'admin.php?page=user_activity&album=' . $category_id,
            catCommentable: $cat_commentable,
            uManageElements: $u_manage_elements,
            infoPhoto: $info_photos,
            infoTitle: $info_title,
            infoCreationSince: $info_creation_since,
            infoCreation: $info_creation,
            infoDirectSub: $info_direct_sub,
            infoId: $info_id,
            infoLastModifiedSince: $info_last_modified_since,
            infoLastModified: $info_last_modified,
            infoImagesRecursive: $info_images_recursive,
            infoSubcats: $info_subcats,
            nbSubcats: $category['nb_subcats'],
            uManageRanks: $u_manage_ranks,
            cacheKeys: $cache_keys,
            catFullDir: $cat_full_dir,
            catDirName: $cat_dir_name,
            catMinDir: $cat_min_dir,
            uSync: $u_sync,
            representant: $representant,
            parentCategory: $parent_category,
            pwgToken: $csrfService
                ->getToken(),
        ));

        $eventDispatcher->dispatch(new LocEndCatModify());

        // ----------------------------------------------------------- sending html code
        $template->assignVarFromTemplate('ADMIN_CONTENT', 'cat_modify.latte');
    }

    /**
     * get_complete_dir returns the concatenation of getSiteUrl and
     * getLocalDir
     * Example : "pets > rex > 1_year_old" is on the the same site as the
     * Piwigo files and this category has 22 for identifier
     * getCompleteDir(22) returns "./galleries/pets/rex/1_year_old/"
     */
    private function getCompleteDir(int|string $category_id, CategoryService $categoryService, EntityManagerInterface $entityManager): string
    {
        return $this->getSiteUrl($category_id, $categoryService, $entityManager) . $this->getLocalDir($category_id, $categoryService);
    }

    /**
     * getLocalDir returns an array with complete path without the site url
     * Example : "pets > rex > 1_year_old" is on the the same site as the
     * Piwigo files and this category has 22 for identifier
     * getLocalDir(22) returns "pets/rex/1_year_old/"
     */
    private function getLocalDir(int|string $category_id, CategoryService $categoryService): string
    {
        $local_dir = '';

        // A $page['plain_structure'] category-structure cache key shortcut
        // would be dead code -- nothing in the codebase ever populates
        // it -- so this always takes the DB-lookup path.
        $uppercats = $categoryService->getCategoryUppercatsById((int) $category_id);
        if ($uppercats === null) {
            throw new Exception(__FUNCTION__ . "(): category #{$category_id} not found");
        }

        $upper_array = explode(',', $uppercats);

        $database_dirs = [];
        foreach ($categoryService->getDirsByIds(explode(',', $uppercats)) as $dir_row_id => $dir) {
            $database_dirs[$dir_row_id] = $dir ?? '';
        }
        foreach ($upper_array as $id) {
            $local_dir .= ($database_dirs[$id] ?? '') . '/';
        }

        return $local_dir;
    }

    /**
     * retrieving the site url : "http://domain.com/gallery/" or
     * simply "./galleries/"
     */
    private function getSiteUrl(int|string $category_id, CategoryService $categoryService, EntityManagerInterface $entityManager): string
    {
        $siteGalleriesUrlLookup = $entityManager->getRepository(SiteEntity::class);
        $galleries_url = $categoryService->getGalleriesUrlForCategory($category_id, $siteGalleriesUrlLookup);
        if ($galleries_url === null) {
            throw new Exception(__FUNCTION__ . "(): category #{$category_id} not found");
        }

        return $galleries_url;
    }

    private function getMinLocalDir(?string $local_dir): ?string
    {
        $full_dir = explode('/', (string) $local_dir);
        if (count($full_dir) <= 3) {
            return $local_dir;
        } else {
            $start = $full_dir[0] . '/' . $full_dir[1];
            $end = end($full_dir);
            $concat = $start . '/&hellip;/' . $end;
            return $concat;
        }
    }
}
