<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Latte\Runtime\Html;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Event\CatModifyPageRendered;
use Piwigo\Admin\Event\CatModifyPageRendering;
use Piwigo\Admin\Projection\CategoryRepresentant;
use Piwigo\Admin\Projection\CatModifyView;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\Category;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\TypedRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Site\SiteEntity;
use Piwigo\Site\SiteRepository;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
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
     * $category is AlbumSubController::handle()'s own real
     * {@see \Piwigo\Category\Projection\Category} instance (same as
     * AlbumNotificationPageRenderer/CatPermPageRenderer's own $category).
     * `Category` is `readonly`, so this method's own view-only computed
     * fields (is_virtual/has_images/nb_subcats/nb_images_recursive/
     * cat_full_dir -- the original array's own spliced-on keys) are tracked
     * as local variables instead of being written back into the object,
     * same pattern as this campaign's other "real row + spliced view-only
     * fields" conversions.
     */
    public function render(Lang $lang, UrlServiceInterface $urlService, Category $category, EventDispatcher $eventDispatcher, PageState $pageState, CurrentUser $currentUser, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, CsrfService $csrfService, ActivityService $activityService, CategoryService $categoryService, HtmlRenderingInterface $htmlRenderer, EntityManagerInterface $entityManager, Renderer $renderer): AdminPageResult
    {
        $eventDispatcher->dispatch(new CatModifyPageRendering());

        // $category->id (this method's own $category parameter, already the
        // real DB row AlbumSubController loaded via a validated cat_id) is
        // the actual source of truth for the category id -- derived once
        // here, up front, and used everywhere below.
        $category_id = $category->id->value;

        // --------------------------------------------------------- form criteria check

        // nullable fields -- Category's own $comment/$dir/$siteId/$idUppercat
        // are real ?string/?string/?int/?int; null becomes '' here for
        // display, same as the original array's own per-key isset() loop.
        $category_comment = $category->comment ?? '';
        $category_dir = $category->dir ?? '';
        $category_site_id = $category->siteId ?? '';
        $category_id_uppercat = $category->idUppercat ?? '';

        // $category_dir is a real string (never null/false/0/[] once
        // narrowed above) -- only an empty or literal '0' value can mean
        // "no dir" now, matching the original array's own defensive
        // multi-type haystack collapsed to what's actually reachable.
        $is_virtual = in_array($category_dir, ['0', ''], true) ? true : false;

        $has_images = $categoryService->hasImages($category_id);

        // number of sub-categories
        $subcat_ids = $categoryService->getSubcatIds([$category_id]);

        $nb_subcats = count($subcat_ids) - 1;

        // Navigation path
        $category_uppercats = $category->uppercats;
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

        // $category_id_uppercat ('' for a root category, otherwise the
        // parent category id -- see its own computation above) and
        // PARENT_CAT_ID (below) feed cat_modify.latte's own `var
        // parent_album`/`related_categories_ids` JS globals, which the
        // move-album jstree widget (themes/admin/default/js/cat_modify.ts)
        // uses to preselect the album's current parent.

        // We show or hide this warning in JS
        $pageState->addWarning(new Html($lang->t('This album is currently locked, visible only to administrators.') . '<span class="icon-cone unlock-album">' . $lang->t('Unlock it') . '</span>'));

        $categories_nav = (string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $navigation));
        $categories_parent_nav = (string) preg_replace('# {2,}#', ' ', (string) preg_replace("#(\r\n|\n\r|\n|\r)#", ' ', $parent_navigation));
        $u_jumpto = $urlService->makeIndexUrl(
            [
                'category' => $category->toArray(),
            ]
        );

        $cat_commentable = null;
        if ($currentConfig->activateComments) {
            $cat_commentable = $category->commentable;
        }

        // manage album elements link
        $image_count = 0;
        $info_title = '';
        $u_manage_elements = null;
        if ($has_images) {
            // Plain '&', not '&amp;': uManageElements reaches
            // cat_modify.latte as a bare {$uManageElements|noescape}
            // print (P59 Batch 5).
            $u_manage_elements = $base_url . 'batch_manager&filter=album-' . $category_id;

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

        $nb_images_recursive = count($image_ids_recursive);

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

        // lastmodified is a real, always-populated string on Category
        // (fromRow()'s own narrowing already resolved the driver's
        // string|null uncertainty once, at the repository layer).
        $category_lastmodified = $category->lastmodified;
        $info_last_modified_since = DateHelper::timeSince($category_lastmodified, 'minute', $format = null, $with_text = true, $with_week = true, $only_last_unit = true);
        $info_last_modified = DateHelper::formatDate($category_lastmodified, ['day', 'month', 'year']);
        $info_images_recursive = $lang->t(
            '%d including sub-albums',
            $nb_images_recursive
        );
        $info_subcats = $lang->t(
            '%d in whole branch',
            $nb_subcats
        );

        $cat_full_dir = null;
        $cat_dir_name = null;
        $cat_min_dir = null;
        $u_sync = null;
        if (! $is_virtual) {
            $cat_full_dir_raw = $this->getCompleteDir($category_id, $categoryService, $entityManager);
            $cat_full_dir = preg_replace('/\/$/', '', $cat_full_dir_raw);
            $cat_dir_name = basename((string) $cat_full_dir);
            $cat_min_dir = $this->getMinLocalDir($cat_full_dir);

            if ($currentConfig->enableSynchronization) {
                // Plain '&', not '&amp;': uSync reaches cat_modify.latte as
                // a bare {$uSync|noescape} print (P59 Batch 5).
                $u_sync = $base_url . 'site_update&site=' . $category_site_id . '&cat_id=' . $category_id;
            }

        }

        // representant management
        // 'representative_picture_id' is a real ?int on Category.
        $category_representative_picture_id = $category->representativePictureId ?? 0;
        $representant = null;
        if ($has_images or $category_representative_picture_id !== 0) {
            // picture to display : the identified representant or the generic random
            // representant ?
            $tpl_picture = null;
            if ($category_representative_picture_id !== 0) {
                $tpl_picture = $categoryService->getCategoryRepresentantProperties($category_representative_picture_id, $urlService, $entityManager, ImageStdParams::MEDIUM);
            }

            // can the admin delete the current representant ?
            // the outer `if` above already guarantees
            // !empty($category_representative_picture_id) whenever
            // !$has_images, since that's the only way its own
            // has_images-or-!empty(...) condition could be true here.
            $tpl_allow_delete = ($has_images and $currentConfig->allowRandomRepresentative)
                || ! $has_images;

            $representant = new CategoryRepresentant(
                picture: $tpl_picture,
                // can the admin choose to set a new random representant ?
                allowSetRandom: $has_images ? true : false,
                allowDelete: $tpl_allow_delete,
            );
        }

        $adminContent = $renderer->render(new CatModifyView(
            categoriesNav: $categories_nav,
            categoriesParentNav: $categories_parent_nav,
            parentCatId: $category_id_uppercat !== '' ? $category_id_uppercat : 0,
            catId: $category_id,
            catName: $category->name,
            catComment: $category_comment,
            isVisible: $category->visible,
            catAdminAccess: $categoryService->catAdminAccess($category_id, $currentUser),
            uDelete: $base_url . 'albums',
            uJumpto: $u_jumpto,
            // Plain '&', not '&amp;': both reach cat_modify.latte as bare
            // {...|noescape} prints (P59 Batch 5).
            uAddPhotosAlbum: $base_url . 'photos_add&album=' . $category_id,
            uMove: $base_url . 'albums&parent_id=' . $category_id,
            uActivity: $urlService->getRootUrl() . 'admin.php?page=user_activity&album=' . $category_id,
            catCommentable: $cat_commentable,
            uManageElements: $u_manage_elements,
            infoPhoto: $info_photos,
            infoTitle: $info_title,
            infoCreationSince: $info_creation_since,
            infoCreation: $info_creation,
            infoDirectSub: $info_direct_sub,
            infoLastModifiedSince: $info_last_modified_since,
            infoLastModified: $info_last_modified,
            infoImagesRecursive: $info_images_recursive,
            infoSubcats: $info_subcats,
            nbSubcats: $nb_subcats,
            catFullDir: $cat_full_dir,
            catDirName: $cat_dir_name,
            catMinDir: $cat_min_dir,
            uSync: $u_sync,
            representant: $representant,
            csrfToken: $csrfService
                ->getToken(),
        ));

        $eventDispatcher->dispatch(new CatModifyPageRendered());

        return new AdminPageResult(content: $adminContent);
    }

    /**
     * get_complete_dir returns the concatenation of getSiteUrl and
     * getLocalDir
     * Example : "pets > rex > 1_year_old" is on the the same site as the
     * Piwigo files and this category has 22 for identifier
     * getCompleteDir(22) returns "./galleries/pets/rex/1_year_old/"
     */
    private function getCompleteDir(int $category_id, CategoryService $categoryService, EntityManagerInterface $entityManager): string
    {
        return $this->getSiteUrl($category_id, $categoryService, $entityManager) . $this->getLocalDir($category_id, $categoryService);
    }

    /**
     * getLocalDir returns an array with complete path without the site url
     * Example : "pets > rex > 1_year_old" is on the the same site as the
     * Piwigo files and this category has 22 for identifier
     * getLocalDir(22) returns "pets/rex/1_year_old/"
     */
    private function getLocalDir(int $category_id, CategoryService $categoryService): string
    {
        $local_dir = '';

        // A $page['plain_structure'] category-structure cache key shortcut
        // would be dead code -- nothing in the codebase ever populates
        // it -- so this always takes the DB-lookup path.
        $uppercats = $categoryService->getCategoryUppercatsById($category_id);
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
    private function getSiteUrl(int $category_id, CategoryService $categoryService, EntityManagerInterface $entityManager): string
    {
        $siteGalleriesUrlLookup = TypedRepository::narrow($entityManager->getRepository(SiteEntity::class), SiteRepository::class);
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
