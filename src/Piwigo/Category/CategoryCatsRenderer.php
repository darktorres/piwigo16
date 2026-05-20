<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Latte\Runtime\Html;
use Piwigo\Common\Enum\Section;
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
use Piwigo\Image\View\PictureViewModel;
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

        if (Section::RecentCats === $ctx->section) {
            $whereExtra = $this->permissionService->getRecentPhotosSql('date_last');
        } else {
            $whereExtra = 'id_uppercat ' . ($ctx->category === null ? 'IS NULL' : '= ' . (is_numeric($ctx->category['id'] ?? null) ? (int) $ctx->category['id'] : 0));
        }

        $perm = $this->permissionService->getSqlConditionFandF(['visible_categories' => 'id'], 'AND');

        $orderBy = (Section::RecentCats === $ctx->section) ? '' : 'ORDER BY `rank`';

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
            $perm->where,
            $perm->params,
            $perm->types,
        );
        $catCatsRows     = $result->rows;
        $totalCategories = $result->total;

        $categories = [];
        $category_ids = [];
        $image_ids = [];
        $user_representative_updates_for = [];

        foreach ($catCatsRows as $row) {
            if ($row['user_representative_picture_id'] !== null && $row['user_representative_picture_id'] !== 0) {
                $image_id = $row['user_representative_picture_id'];
            } elseif ($row['representative_picture_id'] !== null && $row['representative_picture_id'] !== 0) {
                $image_id = $row['representative_picture_id'];
            } elseif (Config::allowRandomRepresentative()) {
                $image_id = $this->categoryService->getRandomImageInCategory($row['id'], $row['uppercats'], $row['count_images'], true);
            } elseif ($row['count_categories'] > 0 and $row['count_images'] > 0) {
                $subPerm  = $this->permissionService->getSqlConditionFandF(['visible_categories' => 'id'], "\n  AND");
                $image_id = $this->categoryRepository->findRandomSubcatRepresentativeForUser(
                    $currentUser->id,
                    $row['uppercats'],
                    $subPerm->where,
                    $subPerm->params,
                    $subPerm->types,
                );
            }

            if (isset($image_id)) {
                if (Config::representativeCacheOnSubcats() and $row['user_representative_picture_id'] !== $image_id) {
                    $user_representative_updates_for[(string) $row['id']] = $image_id;
                }
                $row['representative_picture_id'] = $image_id;
                $image_ids[] = $image_id;
                $categories[] = $row;
                $category_ids[] = $row['id'];
            } else {
                $logger->info(sprintf(
                    '[CategoryCatsRenderer] category #%u was listed in SQL but no image_id found, so it was skipped',
                    $row['id']
                ));
            }
            unset($image_id);
        }

        if (Config::displayFromto()) {
            if (count($category_ids) > 0) {
                $datesPerm = $this->permissionService->getSqlConditionFandF(['visible_categories' => 'category_id', 'visible_images' => 'id'], 'AND');
                $dates_of_category = $this->categoryRepository->findDateRangesForCategoriesKeyedById(
                    $category_ids,
                    $datesPerm->where,
                    $datesPerm->params,
                    $datesPerm->types,
                );
            }
        }

        if (Section::RecentCats === $ctx->section) {
            usort($categories, static fn (array $a, array $b): int => strnatcasecmp($a['global_rank'] ?? '', $b['global_rank'] ?? ''));
        }

        $infos_of_image = [];
        if (count($categories) > 0) {
            $new_image_ids = [];

            $userLevel   = is_numeric($user['level'] ?? null) ? (int) $user['level'] : 0;
            foreach ($this->imageRepository->findByIds($image_ids) as $img) {
                if ($img->level <= $userLevel) {
                    $infos_of_image[(string) $img->id->value] = PictureViewModel::fromImage($img)->toArray();
                    continue;
                }
                foreach ($categories as $idx => $category) {
                    if ($img->id->value !== $category['representative_picture_id']) {
                        continue;
                    }
                    $image_id = $this->categoryService->getRandomImageInCategory($category['id'], $category['uppercats'], $category['count_images'], true);
                    if (isset($image_id) and !in_array($image_id, $image_ids)) {
                        $new_image_ids[] = $image_id;
                    }
                    if (Config::representativeCacheOnLevel()) {
                        $user_representative_updates_for[(string) $category['id']] = $image_id;
                    }
                    $categories[$idx]['representative_picture_id'] = $image_id;
                }
            }

            if (count($new_image_ids) > 0) {
                foreach ($this->imageRepository->findByIds($new_image_ids) as $img) {
                    $infos_of_image[(string) $img->id->value] = PictureViewModel::fromImage($img)->toArray();
                }
            }
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
                // FilterService::updateCategoriesWithFilteredData() above
                // takes the typed shape by-ref through an `array<string, mixed>`
                // signature, so phpstan loses the typed-shape narrowing here.
                // Narrow back to the keys the template-build path consumes.
                $catIdInt           = is_numeric($category['id'] ?? null) ? (int) $category['id'] : 0;
                $catName            = is_string($category['name'] ?? null) ? $category['name'] : '';
                $catUppercats       = is_string($category['uppercats'] ?? null) ? $category['uppercats'] : '';
                $catComment         = is_string($category['comment'] ?? null) ? $category['comment'] : '';
                $catNbImages        = is_numeric($category['nb_images'] ?? null) ? (int) $category['nb_images'] : 0;
                $catCountImages     = is_numeric($category['count_images'] ?? null) ? (int) $category['count_images'] : 0;
                $catCountCategories = is_numeric($category['count_categories'] ?? null) ? (int) $category['count_categories'] : 0;
                $catMaxDateLast     = is_string($category['max_date_last'] ?? null) ? $category['max_date_last'] : null;
                $catDateLast        = is_string($category['date_last'] ?? null) ? $category['date_last'] : null;
                $catRepPicId        = is_numeric($category['representative_picture_id'] ?? null) ? (int) $category['representative_picture_id'] : null;

                if (0 === $catCountImages) {
                    continue;
                }

                $subcatRenderEvent = new RenderCategoryName($catName, 'subcatify_category_name');
                $this->dispatcher->dispatch($subcatRenderEvent);
                $category['name'] = $subcatRenderEvent->categoryName;

                if ($ctx->section === Section::RecentCats) {
                    $name = $this->htmlService->getCatDisplayNameCache($catUppercats, null);
                } else {
                    $name = $subcatRenderEvent->categoryName;
                }

                $infosRaw = ($catRepPicId !== null) ? ($infos_of_image[(string) $catRepPicId] ?? null) : null;
                $representative_infos = is_array($infosRaw) ? $infosRaw : [];

                $descEvent = new RenderCategoryDescription($catComment, 'subcatify_category_description');
                $this->dispatcher->dispatch($descEvent);
                $literalEvent = new RenderCategoryLiteralDescription($descEvent->categoryDescription);
                $this->dispatcher->dispatch($literalEvent);
                $description = $literalEvent->description;

                $tpl_var = array_merge($category, [
                    'ID' => $catIdInt,
                    'representative' => $representative_infos,
                    'TN_ALT' => strip_tags($subcatRenderEvent->categoryName),
                    'URL' => $this->urlService->makeIndexUrl(['category' => $category]),
                    'CAPTION_NB_IMAGES' => new Html($this->categoryService->getDisplayImagesCount(
                        $catNbImages,
                        $catCountImages,
                        $catCountCategories,
                        true,
                        '<br>'
                    )),
                    'DESCRIPTION' => new Html($description),
                    'NAME' => $name,
                ]);
                if (Config::indexNewIcon()) {
                    $tpl_var['icon_ts'] = $this->htmlService->getIcon(
                        $catMaxDateLast,
                        ($catMaxDateLast ?? '') > ($catDateLast ?? '')
                    );
                }

                if (Config::displayFromto() && isset($dates_of_category[$catIdInt])) {
                    $from = $dates_of_category[$catIdInt]['from'];
                    $to   = $dates_of_category[$catIdInt]['to'];
                    if ($from !== null && $from !== '') {
                        $tpl_var['INFO_DATES'] = $this->dateService->formatFromto($from, $to);
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
