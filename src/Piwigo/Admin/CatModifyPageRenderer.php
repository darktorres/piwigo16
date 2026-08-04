<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\SqlDialect;
use Piwigo\Event\Location\LocBeginCatModify;
use Piwigo\Event\Location\LocEndCatModify;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\Template;

/**
 * Ported from admin/cat_modify.php (the "properties" tab of the "album"
 * page slug, dispatched by AlbumSubController).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original cat_modify.php's own (redundant) check_status() call is
 * dropped here -- same precedent as PhotosAddSubController.
 *
 * P23 batch 6f fix: dropped a genuinely dead branch, `if (isset($redirect))
 * { redirect(...); }` -- $redirect is never assigned anywhere in this
 * file, in AlbumSubController.php (its only real caller), or via any
 * extract() call anywhere in the request path (confirmed by a direct
 * grep). Always false in the current codebase.
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
    public function render(UrlServiceInterface $urlService, array $category, \Piwigo\PluginConfig\EventDispatcher $eventDispatcher, \Piwigo\Core\PageState $pageState, \Piwigo\Users\CurrentUser $currentUser, \Piwigo\Template\CurrentTemplate $currentTemplate, \Piwigo\Activity\ActivityService $activityService, \Piwigo\Category\CategoryService $categoryService, \Piwigo\Core\HtmlRenderingInterface $htmlRenderer): void
    {
        $template = $currentTemplate->get();

        $eventDispatcher->dispatchNotify(new LocBeginCatModify());

        // 'id' is the categories table primary key (NOT NULL); AlbumSubController's
        // own fetchAssociative() call (one file over the include boundary PHPStan
        // can't see into) always returns it as numeric. Narrow once here and reuse
        // throughout the rest of this method's many uses of the category id.
        //
        // P26/SEC-40: this replaces a real bug found while auditing raw
        // superglobal reads -- the original `isset($_GET['cat_id']) &&
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
            $parent_navigation = Lang::t('Root');
        }

        // ----------------------------------------------------- template initialization
        $template->set_filename('album_properties', 'cat_modify.tpl');

        $base_url = $urlService->getRootUrl() . 'admin.php?page=';
        $cat_list_url = $base_url . 'albums';

        // 'id_uppercat' is one of the nullable fields normalized to '' above
        // (root category); otherwise it is the parent category id.
        //
        // Bug found while writing coverage for this branch: this used to be
        // `is_string($category['id_uppercat']) ? ... : ''`, which assumed
        // the pre-ORM raw-row shape (mysqli hands back every column as a
        // string). $category actually comes from
        // Category\Projection\Category::toArray() (see this method's own
        // top-of-file docblock), whose 'id_uppercat' key is a real ?int --
        // so `is_string(...)` was always false for every category that
        // actually has a parent, silently collapsing $category_id_uppercat
        // to '' and PARENT_CAT_ID (below) to 0 for every sub-album. That fed
        // straight into cat_modify.tpl's own `var parent_album`/
        // `related_categories_ids` JS globals, which the move-album jstree
        // widget (themes/admin/default/js/cat_modify.js) uses to preselect
        // the album's real current parent -- always showing root selected
        // instead. Accept int or string here, same pattern this file's own
        // site_id handling already uses just below (representative_picture_id
        // decision, ~L299).
        $category_id_uppercat_raw = $category['id_uppercat'];
        $category_id_uppercat = (is_int($category_id_uppercat_raw) || is_string($category_id_uppercat_raw)) ? $category_id_uppercat_raw : '';

        $self_url = $cat_list_url;
        if ($category_id_uppercat !== '') {
            $self_url .= '&amp;parent_id=' . $category_id_uppercat;
        }

        // We show or hide this warning in JS
        $pageState->addWarning(Lang::t('This album is currently locked, visible only to administrators.') . '<span class="icon-cone unlock-album">' . Lang::t('Unlock it') . '</span>');

        $template->assign(
            [
                'CATEGORIES_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation)),
                'CATEGORIES_PARENT_NAV' => preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $parent_navigation)),
                'PARENT_CAT_ID' => $category_id_uppercat !== '' ? $category_id_uppercat : 0,
                'CAT_ID' => $category_id,
                'CAT_NAME' => @htmlspecialchars(is_string($category['name']) ? $category['name'] : ''),
                'CAT_COMMENT' => @htmlspecialchars(is_string($category['comment']) ? $category['comment'] : ''),
                'IS_VISIBLE' => SqlDialect::booleanToString((bool) $category['visible']),
                'CAT_ADMIN_ACCESS' => $categoryService->catAdminAccess($category_id, $currentUser),

                'U_DELETE' => $base_url . 'albums',

                'U_JUMPTO' => $urlService->makeIndexUrl(
                    [
                        'category' => $category,
                    ]
                ),

                'U_ADD_PHOTOS_ALBUM' => $base_url . 'photos_add&amp;album=' . $category_id,
                'U_CHILDREN' => $cat_list_url . '&amp;parent_id=' . $category_id,
                'U_MOVE' => $base_url . 'albums&amp;parent_id=' . $category_id,
                'U_ACTIVITY' => $urlService->getRootUrl() . 'admin.php?page=user_activity&album=' . $category_id,
            ]
        );

        if (\Piwigo\Config\CurrentConfig::activateComments()) {
            $template->assign('CAT_COMMENTABLE', SqlDialect::booleanToString((bool) $category['commentable']));
        }

        // manage album elements link
        $image_count = 0;
        $info_title = '';
        if ($category['has_images']) {
            $template->assign(
                'U_MANAGE_ELEMENTS',
                $base_url . 'batch_manager&amp;filter=album-' . $category_id
            );

            $row = $categoryService->getPhotoCountAndDateRange($category_id);
            if ($row === false) {
                throw new \Exception("cat_modify: aggregate photo count/date query returned no row for category #{$category_id}");
            }
            $image_count = $row[0] ?? null;
            $min_date = $row[1] ?? null;
            $max_date = $row[2] ?? null;
            // date_available is a NOT NULL column but the driver still types every
            // fetched value as string|null; format_date()'s phpDoc param forbids
            // null, so fall back to false (its "no date" sentinel) if that ever
            // isn't the case.
            $min_date = is_string($min_date) ? $min_date : false;
            $max_date = is_string($max_date) ? $max_date : false;

            if ($min_date === $max_date) {
                $info_title = Lang::t(
                    'This album contains %d photos, added on %s.',
                    $image_count,
                    \Piwigo\Core\DateHelper::formatDate($min_date)
                );
            } else {
                $info_title = Lang::t(
                    'This album contains %d photos, added between %s and %s.',
                    $image_count,
                    \Piwigo\Core\DateHelper::formatDate($min_date),
                    \Piwigo\Core\DateHelper::formatDate($max_date)
                );
            }

        }
        $info_photos = Lang::t('%d photos', $image_count);

        $template->assign(
            [
                'INFO_PHOTO' => $info_photos,
                'INFO_TITLE' => $info_title,
            ]
        );

        // total number of images under this category (including sub-categories)
        $image_ids_recursive = $categoryService->getDistinctImageIdsInCategories($subcat_ids);

        $category['nb_images_recursive'] = count($image_ids_recursive);

        // date creation
        $occured_on = $activityService
            ->getOccuredOnForObject($category_id, 'album', 'add');

        if ($occured_on !== null) {
            $template->assign(
                [
                    'INFO_CREATION_SINCE' => \Piwigo\Core\DateHelper::timeSince($occured_on, 'day', $format = null, $with_text = true, $with_week = true, $only_last_unit = true),
                    'INFO_CREATION' => \Piwigo\Core\DateHelper::formatDate($occured_on, ['day', 'month', 'year']),
                ]
            );
        }

        // Sub Albums
        $nb_direct_sub = count($categoryService->getChildrenOfParent($category_id));

        $template->assign(
            [
                'INFO_DIRECT_SUB' => Lang::t(
                    '%d sub-albums',
                    $nb_direct_sub
                ),
            ]
        );

        // lastmodified is a NOT NULL DATETIME column, but the driver still types
        // every fetched value as string|null; narrow with real fallbacks (matching
        // the min_date/max_date/occured_on pattern above) rather than assuming.
        $category_lastmodified = is_string($category['lastmodified']) ? $category['lastmodified'] : null;
        $template->assign(
            [
                'INFO_ID' => Lang::t('Numeric identifier : %d', $category_id),
                'INFO_LAST_MODIFIED_SINCE' => \Piwigo\Core\DateHelper::timeSince($category_lastmodified ?? '', 'minute', $format = null, $with_text = true, $with_week = true, $only_last_unit = true),
                'INFO_LAST_MODIFIED' => \Piwigo\Core\DateHelper::formatDate($category_lastmodified ?? false, ['day', 'month', 'year']),
                'INFO_IMAGES_RECURSIVE' => Lang::t(
                    '%d including sub-albums',
                    $category['nb_images_recursive']
                ),
                'INFO_SUBCATS' => Lang::t(
                    '%d in whole branch',
                    $category['nb_subcats']
                ),

                'NB_SUBCATS' => $category['nb_subcats'],
            ]
        );

        $template->assign([
            'U_MANAGE_RANKS' => $base_url . 'element_set_ranks&amp;cat_id=' . $category_id,
            'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($urlService, ['categories']),
        ]);

        if (! (bool) $category['is_virtual']) {
            $category['cat_full_dir'] = $this->getCompleteDir($category_id, $categoryService);
            $category_full_dir = preg_replace('/\/$/', '', $category['cat_full_dir']);
            $template->assign(
                [
                    'CAT_FULL_DIR' => $category_full_dir,
                ]
            );
            $template->assign('CAT_DIR_NAME', basename((string) $category_full_dir));
            $template->assign('CAT_MIN_DIR', $this->getMinLocalDir($category_full_dir));

            if (\Piwigo\Config\CurrentConfig::enableSynchronization()) {
                $category_site_id = $category['site_id'];
                $category_site_id = (is_int($category_site_id) || is_string($category_site_id)) ? $category_site_id : '';
                $template->assign(
                    'U_SYNC',
                    $base_url . 'site_update&amp;site=' . $category_site_id . '&amp;cat_id=' . $category_id
                );
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
        if ($category['has_images'] or ! in_array($category_representative_picture_id, [0, '0', ''], true)) {
            $tpl_representant = [];

            // picture to display : the identified representant or the generic random
            // representant ?
            if (! in_array($category_representative_picture_id, [0, '0', ''], true)) {
                $tpl_representant['picture'] = $categoryService->getCategoryRepresentantProperties($category_representative_picture_id, $urlService, ImageStdParams::MEDIUM);
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
                 and \Piwigo\Config\CurrentConfig::allowRandomRepresentative())
                or ! $category['has_images']) {
                $tpl_representant['ALLOW_DELETE'] = true;
            }
            $template->assign('representant', $tpl_representant);
        }

        if ((bool) $category['is_virtual']) {
            $template->assign('parent_category', $category_id_uppercat === '' ? [] : [$category_id_uppercat]);
        }

        $template->assign('PWG_TOKEN', new \Piwigo\Csrf\CsrfService()->getToken());

        $eventDispatcher->dispatchNotify(new LocEndCatModify());

        // ----------------------------------------------------------- sending html code
        $template->assign_var_from_handle('ADMIN_CONTENT', 'album_properties');
    }

    /**
     * get_complete_dir returns the concatenation of getSiteUrl and
     * getLocalDir
     * Example : "pets > rex > 1_year_old" is on the the same site as the
     * Piwigo files and this category has 22 for identifier
     * getCompleteDir(22) returns "./galleries/pets/rex/1_year_old/"
     */
    private function getCompleteDir(int|string $category_id, \Piwigo\Category\CategoryService $categoryService): string
    {
        return $this->getSiteUrl($category_id, $categoryService) . $this->getLocalDir($category_id, $categoryService);
    }

    /**
     * getLocalDir returns an array with complete path without the site url
     * Example : "pets > rex > 1_year_old" is on the the same site as the
     * Piwigo files and this category has 22 for identifier
     * getLocalDir(22) returns "pets/rex/1_year_old/"
     */
    private function getLocalDir(int|string $category_id, \Piwigo\Category\CategoryService $categoryService): string
    {
        $local_dir = '';

        // The legacy $page['plain_structure'] category-structure cache key
        // this used to read as a shortcut is confirmed dead (nothing in the
        // codebase ever populated it) and $page is retired as a channel
        // altogether, so this always takes the DB-lookup path now.
        $uppercats = $categoryService->getCategoryUppercatsById((int) $category_id);
        if ($uppercats === null) {
            throw new \Exception(__FUNCTION__ . "(): category #{$category_id} not found");
        }

        $upper_array = explode(',', $uppercats);

        $database_dirs = [];
        foreach ($categoryService->getDirsByIds(explode(',', $uppercats)) as $dir_row_id => $dir) {
            $database_dirs[$dir_row_id] = is_scalar($dir) ? (string) $dir : '';
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
    private function getSiteUrl(int|string $category_id, \Piwigo\Category\CategoryService $categoryService): string
    {
        $galleries_url = $categoryService->getGalleriesUrlForCategory($category_id);
        if ($galleries_url === null) {
            throw new \Exception(__FUNCTION__ . "(): category #{$category_id} not found");
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
