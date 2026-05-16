<?php

declare(strict_types=1);

namespace Piwigo\Menu;

use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\StringUtil;
use Piwigo\Filter\FilterContextRegistry;
use Piwigo\Filter\FilterService;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class MenubarRenderer
{
    public function __construct(
        private CategoryService $categoryService,
        private CommentService $commentService,
        private PermissionService $permissionService,
        private TagService $tagService,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private FilterService $filterService,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function render(): void
    {
        $filter = FilterContextRegistry::current();
        $template = TemplateRegistry::current();
        $ctx = SectionContextRegistry::current();
        $user = CurrentUser::get()->rawAttributes;

        $menu = new BlockManager('menubar', $this->dispatcher);

        if (Config::guestAccess() or !$this->permissionService->isAGuest()) {
            $menu->loadRegisteredBlocks();
        }
        $menu->prepareDisplay();

        if ($ctx->section === 'search' && $ctx->qsearchDetails !== []) {
            $qsearchQ = is_scalar($ctx->qsearchDetails['q'] ?? null) ? (string) $ctx->qsearchDetails['q'] : '';
            $template->assign('QUERY_SEARCH', htmlspecialchars($qsearchQ));
        }

        if (($block = $menu->getBlock('mbLinks')) and !empty(Config::links())) {
            $block->data = [];
            foreach (Config::links() as $url => $url_data) {
                if (!is_array($url_data)) {
                    $url_data = ['label' => $url_data];
                }

                if (
                    (!isset($url_data['eval_visible']))
                    or
                    (eval($url_data['eval_visible']))
                ) {
                    $tpl_var = ['URL' => $url, 'LABEL' => $url_data['label']];

                    if (!isset($url_data['new_window']) or $url_data['new_window']) {
                        $tpl_var['new_window'] = [
                            'NAME' => ($url_data['nw_name'] ?? ''),
                            'FEATURES' => ($url_data['nw_features'] ?? ''),
                        ];
                    }
                    $block->data[] = $tpl_var;
                }
            }
            if (!empty($block->data)) {
                $block->template = 'menubar_links.latte';
            }
        }

        $block = $menu->getBlock('mbCategories');
        if (Config::menubarFilterIcon() and !empty(Config::filterPages()) and $this->filterService->getFilterPageValue('used')) {
            if ($filter->enabled) {
                $template->assign('U_STOP_FILTER', $this->urlService->addUrlParams($this->urlService->makeIndexUrl([]), ['filter' => 'stop']));
            } else {
                $template->assign(
                    'U_START_FILTER',
                    $this->urlService->addUrlParams($this->urlService->makeIndexUrl([]), ['filter' => 'start-recent-' . (is_scalar($user['recent_period'] ?? null) ? (string) $user['recent_period'] : '')])
                );
            }
        }

        if ($block != null) {
            $block->data = [
                'NB_PICTURE' => $user['nb_total_images'] ?? 0,
                'MENU_CATEGORIES' => $this->categoryService->getCategoriesMenu(),
                'U_CATEGORIES' => $this->urlService->makeIndexUrl(['section' => 'categories']),
            ];
            $block->template = 'menubar_categories.latte';
        }

        $block = $menu->getBlock('mbRelatedCategories');
        $items = $ctx->items;
        if ($items !== [] and count($items) < Config::relatedAlbumsMaximumItemsToCompute() and $block != null) {
            /** @var list<int> $exclude_cat_ids */
            $exclude_cat_ids = [];
            $category = $ctx->category;
            if ($category !== null) {
                if (isset($category['id']) && is_numeric($category['id'])) {
                    $exclude_cat_ids[] = (int) $category['id'];
                }
                $combined = $ctx->combinedCategories ?? [];
                foreach ($combined as $cat) {
                    if (is_array($cat) && isset($cat['id']) && is_numeric($cat['id'])) {
                        $exclude_cat_ids[] = (int) $cat['id'];
                    }
                }
            }

            $block->data = ['MENU_CATEGORIES' => $this->categoryService->getRelatedCategoriesMenu($items, $exclude_cat_ids)];
            if (count($block->data['MENU_CATEGORIES']) > 0) {
                $block->template = 'menubar_related_categories.latte';
            }
        }

        $block = $menu->getBlock('mbTags');
        if ($block != null and 'picture' != StringUtil::scriptBasename()) {
            $tags = $this->tagService->getAvailableTags();
            usort($tags, fn (mixed $a, mixed $b): int => $this->tagService->tagsCounterCompare(is_array($a) ? $a : [], is_array($b) ? $b : []));
            $tags = array_slice($tags, 0, Config::menubarTagCloudItemsNumber());
            foreach ($tags as $tag) {
                $tagArr = is_array($tag) ? $tag : [];
                $block->data[] = array_merge($tagArr, ['URL' => $this->urlService->makeIndexUrl(['tags' => [$tag]])]);
            }
            if (!empty($block->data)) {
                $block->template = 'menubar_tags.latte';
            }
        }

        if (($block = $menu->getBlock('mbSpecials')) != null) {
            if (!$this->permissionService->isAGuest()) {
                $block->data['favorites'] = [
                    'URL' => $this->urlService->makeIndexUrl(['section' => 'favorites']),
                    'TITLE' => Lang::t('display your favorites photos'),
                    'NAME' => Lang::t('Your favorites'),
                ];
            }

            $block->data['most_visited'] = [
                'URL' => $this->urlService->makeIndexUrl(['section' => 'most_visited']),
                'TITLE' => Lang::t('display most visited photos'),
                'NAME' => Lang::t('Most visited'),
            ];

            if (Config::rateEnabled()) {
                $block->data['best_rated'] = [
                    'URL' => $this->urlService->makeIndexUrl(['section' => 'best_rated']),
                    'TITLE' => Lang::t('display best rated photos'),
                    'NAME' => Lang::t('Best rated'),
                ];
            }

            $block->data['recent_pics'] = [
                'URL' => $this->urlService->makeIndexUrl(['section' => 'recent_pics']),
                'TITLE' => Lang::t('display most recent photos'),
                'NAME' => Lang::t('Recent photos'),
            ];

            $block->data['recent_cats'] = [
                'URL' => $this->urlService->makeIndexUrl(['section' => 'recent_cats']),
                'TITLE' => Lang::t('display recently updated albums'),
                'NAME' => Lang::t('Recent albums'),
            ];

            $block->data['random'] = [
                'URL' => $this->urlGenerator->random(),
                'TITLE' => Lang::t('display a set of random photos'),
                'NAME' => Lang::t('Random photos'),
                'REL' => 'rel="nofollow"',
            ];

            $block->data['calendar'] = [
                'URL' => $this->urlService->makeIndexUrl([
                    'chronology_field' => (Config::calendarDatefield() == 'date_available' ? 'posted' : 'created'),
                    'chronology_style' => 'monthly',
                    'chronology_view' => 'calendar',
                ]),
                'TITLE' => Lang::t('display each day with photos, month per month'),
                'NAME' => Lang::t('Calendar'),
                'REL' => 'rel="nofollow"',
            ];
            $block->template = 'menubar_specials.latte';
        }

        if (($block = $menu->getBlock('mbMenu')) != null) {
            $block->data['qsearch'] = true;
            $template->assign('U_QSEARCH', $this->urlGenerator->qsearch());

            $block->data['tags'] = [
                'TITLE' => Lang::t('display available tags'),
                'NAME' => Lang::t('Tags'),
                'URL' => $this->urlGenerator->tagsPage(),
                'COUNTER' => $this->tagService->getNbAvailableTags(),
            ];

            $block->data['search'] = [
                'TITLE' => Lang::t('search'),
                'NAME' => Lang::t('Search'),
                'URL' => $this->urlGenerator->searchPage(),
                'REL' => 'rel="search"',
            ];

            if (Config::activateComments()) {
                $block->data['comments'] = [
                    'TITLE' => Lang::t('display last user comments'),
                    'NAME' => Lang::t('Comments'),
                    'URL' => $this->urlGenerator->comments(),
                    'COUNTER' => $this->commentService->getNbAvailable(),
                ];
            }

            $block->data['about'] = [
                'TITLE' => Lang::t('About Piwigo'),
                'NAME' => Lang::t('About'),
                'URL' => $this->urlGenerator->about(),
            ];

            $block->data['rss'] = [
                'TITLE' => Lang::t('RSS feed'),
                'NAME' => Lang::t('Notification'),
                'URL' => $this->urlGenerator->notification(),
                'REL' => 'rel="nofollow"',
            ];
            $block->template = 'menubar_menu.latte';
        }

        if ($this->permissionService->isAGuest()) {
            $template->assign([
                'U_LOGIN' => $this->urlGenerator->identification(),
                'U_LOST_PASSWORD' => $this->urlGenerator->password(),
                'AUTHORIZE_REMEMBERING' => Config::authorizeRemembering(),
            ]);
            if (Config::allowUserRegistration()) {
                $template->assign('U_REGISTER', $this->urlGenerator->register());
            }
        } else {
            $template->assign('USERNAME', stripslashes(CurrentUser::get()->username));
            if ($this->permissionService->isAutorizeStatus(AccessLevel::Classic)) {
                $template->assign('U_PROFILE', $this->urlGenerator->profile());
            }
            if (!Config::apacheAuthentication()) {
                $template->assign('U_LOGOUT', UrlService::getRootUrl() . '?act=logout');
            }
            if ($this->permissionService->isAdmin()) {
                $template->assign('U_ADMIN', $this->urlGenerator->admin());
            }
        }
        if (($block = $menu->getBlock('mbIdentification')) != null) {
            $block->template = 'menubar_identification.latte';
        }
        $menu->apply('MENUBAR', 'menubar.latte');
    }
}
