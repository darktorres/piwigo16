<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Latte\Runtime\Html;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\DebugCollector;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Event\Location\LocBeginIndexCategoryThumbnails;
use Piwigo\Event\Location\LocBeginIndexCategoryThumbnailsQuery;
use Piwigo\Event\Location\LocEndIndexCategoryThumbnails;
use Piwigo\Event\Picture\GetIndexAlbumDerivativeParams;
use Piwigo\Event\Template\RenderCategoryDescription;
use Piwigo\Event\Template\RenderCategoryLiteralDescription;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Filter\FilterService;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Page\PaginationService;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class CategoryCatsRenderer
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private DateService $dateService,
        private FilterService $filterService,
        private HtmlService $htmlService,
        private ImageRepository $imageRepository,
        private PermissionService $permissionService,
        private UrlService $urlService,
        private DebugCollector $debugCollector,
        private PaginationService $paginationService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function render(): void
    {
        $template = TemplateRegistry::current();
        $logger = LoggerRegistry::current();
        $ctx = SectionContextRegistry::current();
        $currentUser = CurrentUser::get();
        $user = $currentUser->rawAttributes;

        if ('recent_cats' === $ctx->section) {
            $whereExtra = $this->permissionService->getRecentPhotosSql('date_last');
        } else {
            $whereExtra = 'id_uppercat ' . ($ctx->category === null ? 'IS NULL' : '= ' . (is_numeric($ctx->category['id'] ?? null) ? (int) $ctx->category['id'] : 0));
        }

        [$permSql, $permParams, $permTypes] = $this->permissionService->getSqlConditionFandF(['visible_categories' => 'id'], 'AND');

        $orderBy = ('recent_cats' === $ctx->section) ? '' : 'ORDER BY `rank`';

        // LocBeginIndexCategoryThumbnailsQuery is preserved as a no-op event
        // for plugin compatibility — plugins that rebuilt the SQL string can
        // no longer intercept it now that composition lives in the repository.
        $queryEvent = new LocBeginIndexCategoryThumbnailsQuery('');
        $this->dispatcher->dispatch($queryEvent);

        $result = $this->categoryRepository->findCatsForThumbnailsWithFoundRows(
            $currentUser->id,
            $whereExtra,
            $orderBy,
            Config::nbCategoriesPage(),
            $ctx->startcat,
            $permSql,
            $permParams,
            $permTypes,
        );
        $catCatsRows     = $result['rows'];
        $totalCategories = $result['total'];

        $categories = [];
        $category_ids = [];
        $image_ids = [];
        $user_representative_updates_for = [];

        foreach ($catCatsRows as $row) {
            $row['is_child_date_last'] = ($row['max_date_last'] ?? null) > ($row['date_last'] ?? null);

            if (!empty($row['user_representative_picture_id'])) {
                $image_id = $row['user_representative_picture_id'];
            } elseif (!empty($row['representative_picture_id'])) {
                $image_id = $row['representative_picture_id'];
            } elseif (Config::allowRandomRepresentative()) {
                $image_id = $this->categoryService->getRandomImageInCategory($row);
            } elseif ($row['count_categories'] > 0 and $row['count_images'] > 0) {
                $rowUppercatsRaw      = $row['uppercats'] ?? null;
                $rowUppercatsForQuery = is_string($rowUppercatsRaw) ? $rowUppercatsRaw : '';
                [$subPermSql, $subPermParams, $subPermTypes] = $this->permissionService->getSqlConditionFandF(['visible_categories' => 'id'], "\n  AND");
                $image_id = $this->categoryRepository->findRandomSubcatRepresentativeForUser(
                    $currentUser->id,
                    $rowUppercatsForQuery,
                    $subPermSql,
                    $subPermParams,
                    $subPermTypes,
                );
            }

            if (isset($image_id)) {
                if (Config::representativeCacheOnSubcats() and ($row['user_representative_picture_id'] ?? null) != $image_id) {
                    $rowIdRaw5 = $row['id'] ?? null;
                    $user_representative_updates_for[is_scalar($rowIdRaw5) ? (string) $rowIdRaw5 : ''] = $image_id;
                }
                $row['representative_picture_id'] = $image_id;
                $image_ids[] = $image_id;
                $categories[] = $row;
                $category_ids[] = $row['id'] ?? null;
            } else {
                $logger->info(sprintf(
                    '[CategoryCatsRenderer] category #%u was listed in SQL but no image_id found, so it was skipped',
                    is_numeric($row['id']) ? (int) $row['id'] : 0
                ));
            }
            unset($image_id);
        }

        if (Config::displayFromto()) {
            if (count($category_ids) > 0) {
                [$datesPermSql, $datesPermParams, $datesPermTypes] = $this->permissionService->getSqlConditionFandF(['visible_categories' => 'category_id', 'visible_images' => 'id'], 'AND');
                $catIdsInt = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $category_ids);
                $dates_of_category = $this->categoryRepository->findDateRangesForCategoriesKeyedById(
                    $catIdsInt,
                    $datesPermSql,
                    $datesPermParams,
                    $datesPermTypes,
                );
            }
        }

        if ('recent_cats' == $ctx->section) {
            usort($categories, $this->categoryService->globalRankCompare(...));
        }

        $infos_of_image = [];
        if (count($categories) > 0) {
            $new_image_ids = [];

            $imageIdsInt = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $image_ids);
            foreach ($this->imageRepository->findByIds($imageIdsInt) as $row) {
                if ($row['level'] <= $user['level']) {
                    $infos_of_image[is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] = $row;
                } else {
                    foreach ($categories as &$category) {
                        if ($row['id'] == $category['representative_picture_id']) {
                            $image_id = $this->categoryService->getRandomImageInCategory($category);
                            if (isset($image_id) and !in_array($image_id, $image_ids)) {
                                $new_image_ids[] = $image_id;
                            }
                            if (Config::representativeCacheOnLevel()) {
                                $catIdRaw = $category['id'] ?? null;
                                $user_representative_updates_for[is_scalar($catIdRaw) ? (string) $catIdRaw : ''] = $image_id;
                            }
                            $category['representative_picture_id'] = $image_id;
                        }
                    }
                    unset($category);
                }
            }

            if (count($new_image_ids) > 0) {
                foreach ($this->imageRepository->findByIds($new_image_ids) as $row) {
                    $infos_of_image[is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] = $row;
                }
            }

            foreach ($infos_of_image as &$info) {
                $info['src_image'] = new SrcImage($info);
            }
            unset($info);
        }

        if (count($user_representative_updates_for)) {
            $updates = [];
            foreach ($user_representative_updates_for as $cat_id => $image_id) {
                $updates[] = [
                    'cat_id'   => $cat_id,
                    'image_id' => is_int($image_id) ? $image_id : null,
                ];
            }
            $userIdInt = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
            $this->categoryRepository->setUserRepresentativeBatch($userIdInt, $updates);
        }

        if (count($categories) > 0) {
            $this->filterService->updateCategoriesWithFilteredData($categories);

            $this->dispatcher->dispatch(new LocBeginIndexCategoryThumbnails($categories));
            $tpl_thumbnails_var = [];

            foreach ($categories as $category) {
                if (0 == $category['count_images']) {
                    continue;
                }

                $subcatRenderEvent = new RenderCategoryName(
                    is_scalar($category['name'] ?? null) ? (string) $category['name'] : '',
                    'subcatify_category_name'
                );
                $this->dispatcher->dispatch($subcatRenderEvent);
                $category['name'] = $subcatRenderEvent->categoryName;

                if ($ctx->section == 'recent_cats') {
                    $uppercatsRaw = $category['uppercats'] ?? null;
                    $name = $this->htmlService->getCatDisplayNameCache(is_scalar($uppercatsRaw) ? (string) $uppercatsRaw : '', null);
                } else {
                    $name = $category['name'];
                }

                $repPicIdRaw = $category['representative_picture_id'] ?? null;
                $repPicId = (is_string($repPicIdRaw) || is_int($repPicIdRaw)) ? $repPicIdRaw : null;
                $infosRaw = ($repPicId !== null) ? ($infos_of_image[$repPicId] ?? null) : null;
                $representative_infos = is_array($infosRaw) ? $infosRaw : [];

                $commentRaw = $category['comment'] ?? null;
                $descEvent = new RenderCategoryDescription(is_string($commentRaw) ? $commentRaw : '', 'subcatify_category_description');
                $this->dispatcher->dispatch($descEvent);
                $literalEvent = new RenderCategoryLiteralDescription($descEvent->categoryDescription);
                $this->dispatcher->dispatch($literalEvent);
                $description = $literalEvent->description;

                $tpl_var = array_merge($category, [
                    'ID' => $category['id'],
                    'representative' => $representative_infos,
                    'TN_ALT' => strip_tags($category['name']),
                    'URL' => $this->urlService->makeIndexUrl(['category' => $category]),
                    'CAPTION_NB_IMAGES' => new Html($this->categoryService->getDisplayImagesCount(
                        is_numeric($category['nb_images']) ? (int) $category['nb_images'] : 0,
                        is_numeric($category['count_images']) ? (int) $category['count_images'] : 0,
                        is_numeric($category['count_categories']) ? (int) $category['count_categories'] : 0,
                        true,
                        '<br>'
                    )),
                    'DESCRIPTION' => new Html($description),
                    'NAME' => $name,
                ]);
                if (Config::indexNewIcon()) {
                    $maxDateLastRaw = $category['max_date_last'] ?? null;
                    $tpl_var['icon_ts'] = $this->htmlService->getIcon(
                        is_scalar($maxDateLastRaw) ? (string) $maxDateLastRaw : null,
                        (bool) ($category['is_child_date_last'] ?? false)
                    );
                }

                if (Config::displayFromto()) {
                    $catIdRaw = $category['id'] ?? null;
                    $catId = (is_string($catIdRaw) || is_int($catIdRaw)) ? $catIdRaw : null;
                    if ($catId !== null && isset($dates_of_category[$catId])) {
                        $from = $dates_of_category[$catId]['from'] ?? null;
                        $to = $dates_of_category[$catId]['to'] ?? null;
                        if ($from !== null && $from !== '') {
                            $tpl_var['INFO_DATES'] = $this->dateService->formatFromto(
                                (is_string($from) || is_int($from)) ? $from : null,
                                (is_string($to) || is_int($to)) ? $to : null
                            );
                        }
                    }
                }

                $tpl_thumbnails_var[] = $tpl_var;
            }

            $tpl_thumbnails_var_selection = $tpl_thumbnails_var;
            $albumDerivEvent = new GetIndexAlbumDerivativeParams(ImageStdParams::getByType(DerivativeSize::Thumb->value));
            $this->dispatcher->dispatch($albumDerivEvent);
            $derivative_params = $albumDerivEvent->value;
            $catsEvent = new LocEndIndexCategoryThumbnails($tpl_thumbnails_var_selection);
            $this->dispatcher->dispatch($catsEvent);
            $tpl_thumbnails_var_selection = $catsEvent->tplThumbnailsVar;
            $template->assign([
                'maxRequests' => Config::maxRequests(),
                'category_thumbnails' => $tpl_thumbnails_var_selection,
                'derivative_params' => $derivative_params,
            ]);

            $template->assignVarFromTemplate('CATEGORIES', 'mainpage_categories.latte');

            $catsNavigationBar = [];
            if ($totalCategories > Config::nbCategoriesPage()) {
                $catsNavigationBar = $this->paginationService->createNavigationBar(
                    $this->urlService->duplicateIndexUrl([], ['startcat']),
                    $totalCategories,
                    $ctx->startcat,
                    Config::nbCategoriesPage(),
                    true,
                    'startcat'
                );
            }

            $template->assign('cats_navbar', $catsNavigationBar);
        }

        $this->debugCollector->collect('end CategoryCatsRenderer');
    }
}
