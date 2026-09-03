<?php

declare(strict_types=1);

namespace Piwigo\Menu;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\CategoryInfo;
use Piwigo\Comment\AvailableCommentsCounter;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Lang;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\Projection\RecentIcon;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\TypedRepository;
use Piwigo\Filter\FilterService;
use Piwigo\Lang\Translator;
use Piwigo\Menu\Event\CheckMenuLinkVisibility;
use Piwigo\Menu\Projection\MenubarCategoriesView;
use Piwigo\Menu\Projection\MenubarCategoryRow;
use Piwigo\Menu\Projection\MenubarGuestIdentity;
use Piwigo\Menu\Projection\MenubarIdentificationView;
use Piwigo\Menu\Projection\MenubarLinkRow;
use Piwigo\Menu\Projection\MenubarLinksView;
use Piwigo\Menu\Projection\MenubarMenuRow;
use Piwigo\Menu\Projection\MenubarMenuView;
use Piwigo\Menu\Projection\MenubarQuerySearchPageContext;
use Piwigo\Menu\Projection\MenubarRelatedCategoriesView;
use Piwigo\Menu\Projection\MenubarRelatedCategoryRow;
use Piwigo\Menu\Projection\MenubarSpecialRow;
use Piwigo\Menu\Projection\MenubarSpecialsView;
use Piwigo\Menu\Projection\MenubarTagRow;
use Piwigo\Menu\Projection\MenubarTagsView;
use Piwigo\Menu\Projection\MenubarUserIdentity;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;

/**
 * Builds the main menubar's blocks. Injects nothing on its own
 * constructor -- same "no constructor deps" shape as
 * Html\HtmlService/Url\UrlService: cross-domain calls
 * (AccessLevelChecker::isAGuest()/isAuthorizeStatus()/isAdmin(),
 * PageFilterHelper::getFilterPageValue()/scriptBasename(), Lang::t(),
 * AvailableCommentsCounter::count()) call the real OOP classes directly,
 * not plain global-function wrappers. TagService/CategoryService are
 * constructed locally in render() since this renderer itself takes no
 * constructor deps; render() takes UrlServiceInterface as a method
 * parameter for the same reason.
 *
 * SEC-49: the old `eval($url_data['eval_visible'])` call for the
 * external-links block is gone -- Config\MenuLink::$visibilityLinkId
 * (a plain, safely-storable identifier, not raw PHP source) drives a
 * typed Menu\Event\CheckMenuLinkVisibility dispatch instead, reusing the
 * exact dispatch()/subscribedEvents() machinery every
 * PluginConfig\ExtensionInterface plugin already has -- no separate
 * mechanism needed.
 */
final class MenubarRenderer
{
    /**
     * The gallery-navigation-context reads below (section/items/category/
     * combined_categories/qsearch_details) come from
     * SectionContextRegistry::current() -- nullable here (unlike
     * GalleryController/PictureController's own guaranteed-non-null read)
     * since this renderer is also called from 9 other, non-gallery
     * controllers that never populate it.
     *
     * Returns `count_categories` from CategoryService::getCategoriesMenu()
     * (see that method's own docblock); every caller but GalleryController
     * ignores the return value.
     */
    public function render(Lang $lang, AccessLevelChecker $accessLevelChecker, UrlServiceInterface $urlService, FilterState $filterState, SectionContextRegistry $sectionContextRegistry, SessionService $sessionService, DeploymentPolicy $deploymentPolicy, CurrentUser $currentUser, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, EventDispatcher $eventDispatcher, Translator $translator, CurrentLogger $currentLogger, PermissionService $permissionService, EntityManagerInterface $entityManager, Renderer $renderer): ?int
    {
        $template = $currentTemplate->get();
        $section_context = $sectionContextRegistry->current();

        $categoryService = new CategoryService($lang, new CategoryRepository($entityManager, $currentConfig), $permissionService, $currentConfig, $eventDispatcher, $translator, $accessLevelChecker);

        $tagService = new TagService($lang, TypedRepository::narrow($entityManager->getRepository(TagEntity::class), TagRepository::class), $permissionService, new ActivityService(TypedRepository::narrow($entityManager->getRepository(ActivityEntity::class), ActivityRepository::class)), $eventDispatcher, $currentUser, $currentConfig, $currentLogger);

        $menu = new BlockManager('menubar', $eventDispatcher, $currentTemplate, $currentConfig, $renderer);

        // if guest_access is disabled, we only display the menus if the user is identified
        if ($currentConfig->guestAccess or ! $accessLevelChecker->isAGuest()) {
            $menu->loadRegisteredBlocks();
        }
        $menu->prepareDisplay();

        $query_search = null;
        if ($section_context instanceof SectionContext && $section_context->section === Section::Search && $section_context->qsearchDetails !== []) {
            $qsearch_q = $section_context->qsearchDetails['q'] ?? '';
            // Not pre-escaped: both real consumers (index.latte's
            // QUERY_SEARCH, menubar_menu.latte's own value="") print this
            // bare, relying on Latte's own auto-escape once at print time
            // (P59) -- htmlspecialchars()'ing it here too would double-escape.
            $query_search = is_string($qsearch_q) ? $qsearch_q : '';
        }

        if ((bool) ($block = $menu->getBlock('mbLinks')) and ! self::emptyValue($currentConfig->links)) {
            $links = [];
            foreach ($currentConfig->links as $url => $link) {
                if ($link->visibilityLinkId === null or $eventDispatcher->dispatch(new CheckMenuLinkVisibility($link->visibilityLinkId))->visible) {
                    $links[] = new MenubarLinkRow(
                        url: $url,
                        label: $link->label,
                        windowName: $link->newWindow ? $link->nwName : null,
                        windowFeatures: $link->newWindow ? $link->nwFeatures : null,
                    );
                }
            }
            if ($links !== []) {
                $block->raw_content = (string) $renderer->render(new MenubarLinksView($links));
            }
        }

        $block = $menu->getBlock('mbCategories');
        $u_stop_filter = null;
        $u_start_filter = null;
        if ($currentConfig->menubarFilterIcon and ! self::emptyValue($currentConfig->filterPages) and (bool) PageFilterHelper::getFilterPageValue($currentConfig, 'used')) {
            if ($filterState->isEnabled()) {
                $u_stop_filter = $urlService->addUrlParams($urlService->makeIndexUrl([]), [
                    'filter' => 'stop',
                ]);
            } else {
                $recent_period = $currentUser->get()
                    ->rawAttributes['recent_period'] ?? null;
                $recent_period = is_numeric($recent_period) ? (int) $recent_period : (is_string($recent_period) ? $recent_period : 0);
                $u_start_filter = $urlService->addUrlParams($urlService->makeIndexUrl([]), [
                    'filter' => 'start-recent-' . $recent_period,
                ]);
            }
        }

        $categoryCountCategories = null;
        if ($block instanceof DisplayBlock) {
            $categoriesMenu = $categoryService->getCategoriesMenu($section_context?->category, new FilterService($filterState, $sessionService, $translator, $lang, $currentConfig, $eventDispatcher, $entityManager), $urlService, $filterState, $currentUser, $lang);
            $categoryCountCategories = $categoriesMenu['categoryCountCategories'];
            $categoryRows = [];
            foreach ($categoriesMenu['menu'] as $menuRow) {
                $recentIcon = $menuRow['icon_ts'] ?? null;
                $categoryRows[] = new MenubarCategoryRow(
                    level: is_int($menuRow['LEVEL'] ?? null) ? $menuRow['LEVEL'] : 1,
                    name: is_string($menuRow['NAME'] ?? null) ? $menuRow['NAME'] : '',
                    url: is_string($menuRow['URL'] ?? null) ? $menuRow['URL'] : '',
                    title: is_string($menuRow['TITLE'] ?? null) ? $menuRow['TITLE'] : '',
                    selected: ($menuRow['SELECTED'] ?? false) === true,
                    isUppercat: ($menuRow['IS_UPPERCAT'] ?? false) === true,
                    countImages: is_numeric($menuRow['count_images'] ?? null) ? (int) $menuRow['count_images'] : 0,
                    nbImages: is_numeric($menuRow['nb_images'] ?? null) ? (int) $menuRow['nb_images'] : 0,
                    recentIcon: $recentIcon instanceof RecentIcon ? $recentIcon : null,
                );
            }

            $nbTotalImages = $currentUser->get()
                ->rawAttributes['nb_total_images'] ?? null;

            $block->raw_content = (string) $renderer->render(new MenubarCategoriesView(
                categories: $categoryRows,
                categoriesUrl: $urlService->makeIndexUrl([
                    'section' => 'categories',
                ]),
                totalPhotos: is_numeric($nbTotalImages) ? (int) $nbTotalImages : null,
                startFilterUrl: $u_start_filter,
                stopFilterUrl: $u_stop_filter,
                rootUrl: $urlService->getRootUrl(),
                iconDir: $template->themeConf('icon_dir'),
            ));
        }

        $block = $menu->getBlock('mbRelatedCategories');

        $page_items = $section_context?->items;

        if (
            $section_context instanceof SectionContext
            and is_array($page_items)
            and count($page_items) < $currentConfig->relatedAlbumsMaximumItemsToCompute
            and $block instanceof DisplayBlock
            and $page_items !== []
        ) {
            $exclude_cat_ids = [];
            $page_category = $section_context->category;
            $combined_categories = $section_context->combinedCategories;
            if ($page_category !== null) {
                $exclude_cat_ids = [$page_category->id];
                if ($combined_categories !== null) {
                    foreach ($combined_categories as $cat) {
                        $exclude_cat_ids[] = $cat->id;
                    }
                }
            }

            $related_items = $page_items;

            // getRelatedCategoriesMenuWithUrls() itself stays array-shaped
            // (cluster 4's own SectionContext/UrlService territory, not
            // this pass's scope -- its internal dynamic-$parentIdx mutation
            // loop defeats PHPStan's shape tracking regardless of the
            // written value's own type) -- convert at this one boundary.
            $relatedRows = $categoryService->getRelatedCategoriesMenuWithUrls(
                $related_items,
                $urlService,
                $exclude_cat_ids,
                $page_category?->toArray(),
                $combined_categories !== null
                    ? array_map(static fn (CategoryInfo $category): array => $category->toArray(), $combined_categories)
                    : null,
            );

            $relatedCategories = [];
            foreach ($relatedRows as $relatedRow) {
                $relatedCategories[] = new MenubarRelatedCategoryRow(
                    level: is_int($relatedRow['LEVEL'] ?? null) ? $relatedRow['LEVEL'] : 1,
                    name: is_string($relatedRow['name'] ?? null) ? $relatedRow['name'] : '',
                    url: is_string($relatedRow['url'] ?? null) ? $relatedRow['url'] : null,
                    title: is_string($relatedRow['TITLE'] ?? null) ? $relatedRow['TITLE'] : null,
                    countImages: is_int($relatedRow['count_images'] ?? null) ? $relatedRow['count_images'] : null,
                    countCategories: is_int($relatedRow['count_categories'] ?? null) ? $relatedRow['count_categories'] : null,
                );
            }

            if ($relatedCategories !== []) {
                $block->raw_content = (string) $renderer->render(new MenubarRelatedCategoriesView($relatedCategories));
            }
        }

        $block = $menu->getBlock('mbTags');
        if ($block instanceof DisplayBlock and PageFilterHelper::scriptBasename($currentConfig) !== 'picture') {
            $tags = $tagService->getAvailableTags();
            usort($tags, $tagService->tagsCounterCompare(...));
            $tag_cloud_items_number = $currentConfig->menubarTagCloudItemsNumber;
            // Level after the slice, so each tag is sized against the set
            // actually shown -- the same order TagsController uses. This
            // call was missing entirely: getAvailableTags() returns no
            // `level`, so every tag rendered `class="tagLevel "` and the
            // cloud had no size variation at all.
            $tags = $tagService->addLevelToTags(array_slice($tags, 0, $tag_cloud_items_number));

            $tagRows = [];
            foreach ($tags as $tag) {
                $tagRows[] = new MenubarTagRow(
                    url: $urlService->makeIndexUrl([
                        'tags' => [$tag],
                    ]),
                    name: is_string($tag['name']) ? $tag['name'] : '',
                    level: is_int($tag['level']) ? $tag['level'] : 1,
                );
            }

            if ($tagRows !== []) {
                $block->raw_content = (string) $renderer->render(new MenubarTagsView($tagRows));
            }
        }

        if (($block = $menu->getBlock('mbSpecials')) instanceof DisplayBlock) {
            $specials = [];
            if (! $accessLevelChecker->isAGuest()) {// favorites
                $specials[] = new MenubarSpecialRow(
                    url: $urlService->makeIndexUrl([
                        'section' => 'favorites',
                    ]),
                    title: $lang->t('display your favorites photos'),
                    name: $lang->t('Your favorites'),
                );
            }

            $specials[] = new MenubarSpecialRow(
                url: $urlService->makeIndexUrl([
                    'section' => 'most_visited',
                ]),
                title: $lang->t('display most visited photos'),
                name: $lang->t('Most visited'),
            );

            if ($currentConfig->rateEnabled) {
                $specials[] = new MenubarSpecialRow(
                    url: $urlService->makeIndexUrl([
                        'section' => 'best_rated',
                    ]),
                    title: $lang->t('display best rated photos'),
                    name: $lang->t('Best rated'),
                );
            }

            $specials[] = new MenubarSpecialRow(
                url: $urlService->makeIndexUrl([
                    'section' => 'recent_pics',
                ]),
                title: $lang->t('display most recent photos'),
                name: $lang->t('Recent photos'),
            );

            $specials[] = new MenubarSpecialRow(
                url: $urlService->makeIndexUrl([
                    'section' => 'recent_cats',
                ]),
                title: $lang->t('display recently updated albums'),
                name: $lang->t('Recent albums'),
            );

            $specials[] = new MenubarSpecialRow(
                url: $urlService->getRootUrl() . 'random.php',
                title: $lang->t('display a set of random photos'),
                name: $lang->t('Random photos'),
                noFollow: true,
            );

            $specials[] = new MenubarSpecialRow(
                url: $urlService->makeIndexUrl(
                    [
                        'chronology_field' => ($currentConfig->calendarDatefield === 'date_available'
                                                ? 'posted' : 'created'),
                        'chronology_style' => 'monthly',
                        'chronology_view' => 'calendar',
                    ]
                ),
                title: $lang->t('display each day with photos, month per month'),
                name: $lang->t('Calendar'),
                noFollow: true,
            );

            $block->raw_content = (string) $renderer->render(new MenubarSpecialsView($specials));
        }

        if (($block = $menu->getBlock('mbMenu')) instanceof DisplayBlock) {
            $menuLinks = [
                // tags link
                new MenubarMenuRow(
                    url: $urlService->getRootUrl() . 'tags.php',
                    name: $lang->t('Tags'),
                    title: $lang->t('display available tags'),
                    counter: $tagService->getNbAvailableTags(),
                ),
                // search link
                new MenubarMenuRow(
                    url: $urlService->getRootUrl() . 'search.php',
                    name: $lang->t('Search'),
                    title: $lang->t('search'),
                    rel: 'search',
                ),
            ];

            if ($currentConfig->activateComments) {
                // comments link
                $menuLinks[] = new MenubarMenuRow(
                    url: $urlService->getRootUrl() . 'comments.php',
                    name: $lang->t('Comments'),
                    title: $lang->t('display last user comments'),
                    counter: new AvailableCommentsCounter($currentUser, $accessLevelChecker)
                        ->count($permissionService, $entityManager),
                );
            }

            // about link
            $menuLinks[] = new MenubarMenuRow(
                url: $urlService->getRootUrl() . 'about.php',
                name: $lang->t('About'),
                title: $lang->t('About Piwigo'),
            );

            // notification
            $menuLinks[] = new MenubarMenuRow(
                url: $urlService->getRootUrl() . 'notification.php',
                name: $lang->t('Notification'),
                title: $lang->t('RSS feed'),
                rel: 'nofollow',
            );

            foreach ($template->menuItems() as $item) {
                $menuLinks[] = new MenubarMenuRow(
                    url: $item->url,
                    name: $item->label,
                    title: $item->title,
                    counter: $item->counter,
                );
            }

            $block->raw_content = (string) $renderer->render(new MenubarMenuView(
                quickSearch: true,
                links: $menuLinks,
                rootUrl: $urlService->getRootUrl(),
                querySearch: $query_search,
            ));
        }

        if ($accessLevelChecker->isAGuest()) {
            $identity = new MenubarGuestIdentity(
                loginUrl: $urlService->getRootUrl() . 'identification.php',
                lostPasswordUrl: $urlService->getRootUrl() . 'password.php',
                authorizeRemembering: $currentConfig->authorizeRemembering,
                registerUrl: $currentConfig->allowUserRegistration
                    ? $urlService->getRootUrl() . 'register.php'
                    : null,
            );
        } else {
            $identity = new MenubarUserIdentity(
                username: $currentUser->get()
                    ->username->value ?? '',
                profileUrl: $accessLevelChecker->isAuthorizeStatus(AccessLevel::Classic)
                    ? $urlService->getRootUrl() . 'profile.php'
                    : null,
                // the logout link has no meaning with Apache authentication : it is not
                // possible to logout with this kind of authentication.
                logoutUrl: $deploymentPolicy->apacheAuthentication
                    ? null
                    : $urlService->getRootUrl() . '?act=logout',
                adminUrl: $accessLevelChecker->isAdmin()
                    ? $urlService->getRootUrl() . 'admin.php'
                    : null,
            );
        }
        if (($block = $menu->getBlock('mbIdentification')) instanceof DisplayBlock) {
            $block->raw_content = (string) $renderer->render(new MenubarIdentificationView(
                identity: $identity,
                loginRedirect: is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '',
            ));
        }

        $template->assignContext(new MenubarQuerySearchPageContext($query_search));

        $menu->apply();

        return $categoryCountCategories;
    }

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }
}
