<?php

declare(strict_types=1);

namespace Piwigo\Menu;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Piwigo\Activity\ActivityEntity;
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
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Filter\FilterService;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Menu\Event\CheckMenuLinkVisibility;
use Piwigo\Menu\Projection\MenubarIdentificationPageContext;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;

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
    public function render(Lang $lang, AccessLevelChecker $accessLevelChecker, UrlServiceInterface $urlService, FilterState $filterState, SectionContextRegistry $sectionContextRegistry, SessionService $sessionService, DeploymentPolicy $deploymentPolicy, CurrentUser $currentUser, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, EventDispatcher $eventDispatcher, Translator $translator, CurrentLogger $currentLogger, PermissionService $permissionService, EntityManagerInterface $entityManager): ?int
    {
        $template = $currentTemplate->get();
        $section_context = $sectionContextRegistry->current();

        $categoryService = new CategoryService($lang, new CategoryRepository($entityManager, $currentConfig), $permissionService, $currentConfig, $eventDispatcher, $translator, $accessLevelChecker, new UserRepository($entityManager, $eventDispatcher, $currentConfig));

        // ImageService is a throwaway, never-actually-used collaborator
        // here -- TagService only needs it for updateImagesLastmodified(),
        // never called on this render() path.
        $menubarPaths = Kernel::container()->get(Paths::class);
        if (! $menubarPaths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }
        $imageService = new ImageService($entityManager->getRepository(ImageEntity::class), new ActivityService($entityManager->getRepository(ActivityEntity::class)), $sessionService, $eventDispatcher, $currentConfig, $menubarPaths, $categoryService);
        $tagService = new TagService($lang, $entityManager->getRepository(TagEntity::class), $permissionService, new ActivityService($entityManager->getRepository(ActivityEntity::class)), $eventDispatcher, $currentUser, $currentConfig, $currentLogger, $imageService);

        $menu = new BlockManager('menubar', $eventDispatcher, $currentTemplate, $currentConfig);

        // if guest_access is disabled, we only display the menus if the user is identified
        if ($currentConfig->guestAccess or ! $accessLevelChecker->isAGuest()) {
            $menu->loadRegisteredBlocks();
        }
        $menu->prepareDisplay();

        $query_search = null;
        if ($section_context instanceof SectionContext && $section_context->section === Section::Search && $section_context->qsearchDetails !== []) {
            $qsearch_q = $section_context->qsearchDetails['q'] ?? '';
            $qsearch_q = is_string($qsearch_q) ? $qsearch_q : '';
            $query_search = htmlspecialchars($qsearch_q);
        }

        if ((bool) ($block = $menu->getBlock('mbLinks')) and ! self::emptyValue($currentConfig->links)) {
            $block->data = [];
            foreach ($currentConfig->links as $url => $link) {
                if ($link->visibilityLinkId === null or $eventDispatcher->dispatch(new CheckMenuLinkVisibility($link->visibilityLinkId))->visible) {
                    $tpl_var = [
                        'URL' => $url,
                        'LABEL' => $link->label,
                    ];

                    if ($link->newWindow) {
                        $tpl_var['new_window'] =
                          [
                              'NAME' => $link->nwName,
                              'FEATURES' => $link->nwFeatures,
                          ];
                    }
                    $block->data[] = $tpl_var;
                }
            }
            if (! self::emptyValue($block->data)) {
                $block->template = 'menubar_links.latte';
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
            $block->data = [
                'NB_PICTURE' => $currentUser->get()
                    ->rawAttributes['nb_total_images'] ?? null,
                'MENU_CATEGORIES' => $categoriesMenu['menu'],
                'U_CATEGORIES' => $urlService->makeIndexUrl([
                    'section' => 'categories',
                ]),
            ];
            $block->template = 'menubar_categories.latte';
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
            $block->data = [
                'MENU_CATEGORIES' => $categoryService->getRelatedCategoriesMenuWithUrls(
                    $related_items,
                    $urlService,
                    $exclude_cat_ids,
                    $page_category?->toArray(),
                    $combined_categories !== null
                        ? array_map(static fn (CategoryInfo $category): array => $category->toArray(), $combined_categories)
                        : null,
                ),
            ];

            if (! self::emptyValue($block->data['MENU_CATEGORIES'])) {
                $block->template = 'menubar_related_categories.latte';
            }
        }

        $block = $menu->getBlock('mbTags');
        if ($block instanceof DisplayBlock and PageFilterHelper::scriptBasename($currentConfig) !== 'picture') {
            $block->data = [];
            $tags = $tagService->getAvailableTags();
            usort($tags, $tagService->tagsCounterCompare(...));
            $tag_cloud_items_number = $currentConfig->menubarTagCloudItemsNumber;
            $tags = array_slice($tags, 0, $tag_cloud_items_number);
            foreach ($tags as $tag) {
                $block->data[] = array_merge(
                    $tag,
                    [
                        'URL' => $urlService->makeIndexUrl([
                            'tags' => [$tag],
                        ]),
                    ]
                );
            }

            if (! self::emptyValue($block->data)) {
                $block->template = 'menubar_tags.latte';
            }
        }

        if (($block = $menu->getBlock('mbSpecials')) instanceof DisplayBlock) {
            $block->data = [];
            if (! $accessLevelChecker->isAGuest()) {// favorites
                $block->data['favorites'] =
                  [
                      'URL' => $urlService->makeIndexUrl([
                          'section' => 'favorites',
                      ]),
                      'TITLE' => $lang->t('display your favorites photos'),
                      'NAME' => $lang->t('Your favorites'),
                  ];
            }

            $block->data['most_visited'] =
              [
                  'URL' => $urlService->makeIndexUrl([
                      'section' => 'most_visited',
                  ]),
                  'TITLE' => $lang->t('display most visited photos'),
                  'NAME' => $lang->t('Most visited'),
              ];

            if ($currentConfig->rateEnabled) {
                $block->data['best_rated'] =
                 [
                     'URL' => $urlService->makeIndexUrl([
                         'section' => 'best_rated',
                     ]),
                     'TITLE' => $lang->t('display best rated photos'),
                     'NAME' => $lang->t('Best rated'),
                 ];
            }

            $block->data['recent_pics'] =
              [
                  'URL' => $urlService->makeIndexUrl([
                      'section' => 'recent_pics',
                  ]),
                  'TITLE' => $lang->t('display most recent photos'),
                  'NAME' => $lang->t('Recent photos'),
              ];

            $block->data['recent_cats'] =
              [
                  'URL' => $urlService->makeIndexUrl([
                      'section' => 'recent_cats',
                  ]),
                  'TITLE' => $lang->t('display recently updated albums'),
                  'NAME' => $lang->t('Recent albums'),
              ];

            $block->data['random'] =
              [
                  'URL' => $urlService->getRootUrl() . 'random.php',
                  'TITLE' => $lang->t('display a set of random photos'),
                  'NAME' => $lang->t('Random photos'),
                  'REL' => 'rel="nofollow"',
              ];

            $block->data['calendar'] =
              [
                  'URL' => $urlService->makeIndexUrl(
                      [
                          'chronology_field' => ($currentConfig->calendarDatefield === 'date_available'
                                                  ? 'posted' : 'created'),
                          'chronology_style' => 'monthly',
                          'chronology_view' => 'calendar',
                      ]
                  ),
                  'TITLE' => $lang->t('display each day with photos, month per month'),
                  'NAME' => $lang->t('Calendar'),
                  'REL' => 'rel="nofollow"',
              ];
            $block->template = 'menubar_specials.latte';
        }

        if (($block = $menu->getBlock('mbMenu')) instanceof DisplayBlock) {
            $block->data = [];
            // quick search block will be displayed only if data['qsearch'] is set
            // to "yes"
            $block->data['qsearch'] = true;

            // tags link
            $block->data['tags'] =
              [
                  'TITLE' => $lang->t('display available tags'),
                  'NAME' => $lang->t('Tags'),
                  'URL' => $urlService->getRootUrl() . 'tags.php',
                  'COUNTER' => $tagService->getNbAvailableTags(),
              ];

            // search link
            $block->data['search'] =
              [
                  'TITLE' => $lang->t('search'),
                  'NAME' => $lang->t('Search'),
                  'URL' => $urlService->getRootUrl() . 'search.php',
                  'REL' => 'rel="search"',
              ];

            if ($currentConfig->activateComments) {
                // comments link
                $block->data['comments'] =
                  [
                      'TITLE' => $lang->t('display last user comments'),
                      'NAME' => $lang->t('Comments'),
                      'URL' => $urlService->getRootUrl() . 'comments.php',
                      'COUNTER' => new AvailableCommentsCounter($currentUser, $accessLevelChecker)
                          ->count($permissionService, $entityManager),
                  ];
            }

            // about link
            $block->data['about'] =
              [
                  'TITLE' => $lang->t('About Piwigo'),
                  'NAME' => $lang->t('About'),
                  'URL' => $urlService->getRootUrl() . 'about.php',
              ];

            // notification
            $block->data['rss'] =
              [
                  'TITLE' => $lang->t('RSS feed'),
                  'NAME' => $lang->t('Notification'),
                  'URL' => $urlService->getRootUrl() . 'notification.php',
                  'REL' => 'rel="nofollow"',
              ];
            $block->template = 'menubar_menu.latte';
        }

        $u_login = null;
        $u_lost_password = null;
        $authorize_remembering = null;
        $u_register = null;
        $username_value = null;
        $u_profile = null;
        $u_logout = null;
        $u_admin = null;
        if ($accessLevelChecker->isAGuest()) {
            $u_login = $urlService->getRootUrl() . 'identification.php';
            $u_lost_password = $urlService->getRootUrl() . 'password.php';
            $authorize_remembering = $currentConfig->authorizeRemembering;
            if ($currentConfig->allowUserRegistration) {
                $u_register = $urlService->getRootUrl() . 'register.php';
            }
        } else {
            $username = $currentUser->get()
                ->username->value ?? '';
            $username_value = $username;
            if ($accessLevelChecker->isAuthorizeStatus(AccessLevel::Classic)) {
                $u_profile = $urlService->getRootUrl() . 'profile.php';
            }

            // the logout link has no meaning with Apache authentication : it is not
            // possible to logout with this kind of authentication.
            if (! $deploymentPolicy->apacheAuthentication) {
                $u_logout = $urlService->getRootUrl() . '?act=logout';
            }
            if ($accessLevelChecker->isAdmin()) {
                $u_admin = $urlService->getRootUrl() . 'admin.php';
            }
        }
        if (($block = $menu->getBlock('mbIdentification')) instanceof DisplayBlock) {
            $block->template = 'menubar_identification.latte';
        }

        $template->assignContext(new MenubarIdentificationPageContext(
            querySearch: $query_search,
            uStopFilter: $u_stop_filter,
            uStartFilter: $u_start_filter,
            uLogin: $u_login,
            uLostPassword: $u_lost_password,
            authorizeRemembering: $authorize_remembering,
            uRegister: $u_register,
            username: $username_value,
            uProfile: $u_profile,
            uLogout: $u_logout,
            uAdmin: $u_admin,
        ));

        $menu->apply('MENUBAR', 'menubar.latte');

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
