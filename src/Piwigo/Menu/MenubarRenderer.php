<?php

declare(strict_types=1);

namespace Piwigo\Menu;

use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;

final class MenubarRenderer
{
    public function render(): void
    {
        $filter = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
        $template = TemplateRegistry::current();
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $user = CurrentUser::get()->rawAttributes;

        $menu = new BlockManager('menubar');

        if (Config::guestAccess() or !PermissionService::get()->isAGuest()) {
            $menu->loadRegisteredBlocks();
        }
        $menu->prepareDisplay();

        if (($page['section'] ?? null) == 'search' && isset($page['qsearch_details']) && is_array($page['qsearch_details'])) {
            $qsearchQ = is_scalar($page['qsearch_details']['q'] ?? null) ? (string) $page['qsearch_details']['q'] : '';
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
                $block->template = 'menubar_links.tpl';
            }
        }

        $block = $menu->getBlock('mbCategories');
        if (Config::menubarFilterIcon() and !empty(Config::filterPages()) and ServiceLocator::get(Util::class)->getFilterPageValue('used')) {
            if ($filter['enabled']) {
                $template->assign('U_STOP_FILTER', UrlService::get()->addUrlParams(UrlService::get()->makeIndexUrl([]), ['filter' => 'stop']));
            } else {
                $template->assign(
                    'U_START_FILTER',
                    UrlService::get()->addUrlParams(UrlService::get()->makeIndexUrl([]), ['filter' => 'start-recent-' . (is_scalar($user['recent_period'] ?? null) ? (string) $user['recent_period'] : '')])
                );
            }
        }

        if ($block != null) {
            $block->data = [
                'NB_PICTURE' => $user['nb_total_images'] ?? 0,
                'MENU_CATEGORIES' => ServiceLocator::get(CategoryService::class)->getCategoriesMenu(),
                'U_CATEGORIES' => UrlService::get()->makeIndexUrl(['section' => 'categories']),
            ];
            $block->template = 'menubar_categories.tpl';
        }

        $block = $menu->getBlock('mbRelatedCategories');
        $items = is_array($page['items'] ?? null) ? $page['items'] : [];
        if ($items !== [] and count($items) < Config::relatedAlbumsMaximumItemsToCompute() and $block != null) {
            /** @var list<int> $exclude_cat_ids */
            $exclude_cat_ids = [];
            $category = is_array($page['category'] ?? null) ? $page['category'] : null;
            if ($category !== null) {
                if (isset($category['id']) && is_numeric($category['id'])) {
                    $exclude_cat_ids[] = (int) $category['id'];
                }
                $combined = is_array($page['combined_categories'] ?? null) ? $page['combined_categories'] : [];
                foreach ($combined as $cat) {
                    if (is_array($cat) && isset($cat['id']) && is_numeric($cat['id'])) {
                        $exclude_cat_ids[] = (int) $cat['id'];
                    }
                }
            }

            $block->data = ['MENU_CATEGORIES' => ServiceLocator::get(CategoryService::class)->getRelatedCategoriesMenu($items, $exclude_cat_ids)];
            if (count($block->data['MENU_CATEGORIES']) > 0) {
                $block->template = 'menubar_related_categories.tpl';
            }
        }

        $block = $menu->getBlock('mbTags');
        if ($block != null and 'picture' != StringUtil::scriptBasename()) {
            $tags = ServiceLocator::get(TagService::class)->getAvailableTags();
            usort($tags, fn (mixed $a, mixed $b): int => ServiceLocator::get(TagService::class)->tagsCounterCompare(is_array($a) ? $a : [], is_array($b) ? $b : []));
            $tags = array_slice($tags, 0, Config::menubarTagCloudItemsNumber());
            foreach ($tags as $tag) {
                $tagArr = is_array($tag) ? $tag : [];
                $block->data[] = array_merge($tagArr, ['URL' => UrlService::get()->makeIndexUrl(['tags' => [$tag]])]);
            }
            if (!empty($block->data)) {
                $block->template = 'menubar_tags.tpl';
            }
        }

        if (($block = $menu->getBlock('mbSpecials')) != null) {
            if (!PermissionService::get()->isAGuest()) {
                $block->data['favorites'] = [
                    'URL' => UrlService::get()->makeIndexUrl(['section' => 'favorites']),
                    'TITLE' => Lang::t('display your favorites photos'),
                    'NAME' => Lang::t('Your favorites'),
                ];
            }

            $block->data['most_visited'] = [
                'URL' => UrlService::get()->makeIndexUrl(['section' => 'most_visited']),
                'TITLE' => Lang::t('display most visited photos'),
                'NAME' => Lang::t('Most visited'),
            ];

            if (Config::rateEnabled()) {
                $block->data['best_rated'] = [
                    'URL' => UrlService::get()->makeIndexUrl(['section' => 'best_rated']),
                    'TITLE' => Lang::t('display best rated photos'),
                    'NAME' => Lang::t('Best rated'),
                ];
            }

            $block->data['recent_pics'] = [
                'URL' => UrlService::get()->makeIndexUrl(['section' => 'recent_pics']),
                'TITLE' => Lang::t('display most recent photos'),
                'NAME' => Lang::t('Recent photos'),
            ];

            $block->data['recent_cats'] = [
                'URL' => UrlService::get()->makeIndexUrl(['section' => 'recent_cats']),
                'TITLE' => Lang::t('display recently updated albums'),
                'NAME' => Lang::t('Recent albums'),
            ];

            $block->data['random'] = [
                'URL' => ServiceLocator::get(UrlGenerator::class)->random(),
                'TITLE' => Lang::t('display a set of random photos'),
                'NAME' => Lang::t('Random photos'),
                'REL' => 'rel="nofollow"',
            ];

            $block->data['calendar'] = [
                'URL' => UrlService::get()->makeIndexUrl([
                    'chronology_field' => (Config::calendarDatefield() == 'date_available' ? 'posted' : 'created'),
                    'chronology_style' => 'monthly',
                    'chronology_view' => 'calendar',
                ]),
                'TITLE' => Lang::t('display each day with photos, month per month'),
                'NAME' => Lang::t('Calendar'),
                'REL' => 'rel="nofollow"',
            ];
            $block->template = 'menubar_specials.tpl';
        }

        if (($block = $menu->getBlock('mbMenu')) != null) {
            $block->data['qsearch'] = true;

            $block->data['tags'] = [
                'TITLE' => Lang::t('display available tags'),
                'NAME' => Lang::t('Tags'),
                'URL' => ServiceLocator::get(UrlGenerator::class)->tagsPage(),
                'COUNTER' => ServiceLocator::get(TagService::class)->getNbAvailableTags(),
            ];

            $block->data['search'] = [
                'TITLE' => Lang::t('search'),
                'NAME' => Lang::t('Search'),
                'URL' => ServiceLocator::get(UrlGenerator::class)->searchPage(),
                'REL' => 'rel="search"',
            ];

            if (Config::activateComments()) {
                $block->data['comments'] = [
                    'TITLE' => Lang::t('display last user comments'),
                    'NAME' => Lang::t('Comments'),
                    'URL' => ServiceLocator::get(UrlGenerator::class)->comments(),
                    'COUNTER' => ServiceLocator::get(Util::class)->getNbAvailableComments(),
                ];
            }

            $block->data['about'] = [
                'TITLE' => Lang::t('About Piwigo'),
                'NAME' => Lang::t('About'),
                'URL' => ServiceLocator::get(UrlGenerator::class)->about(),
            ];

            $block->data['rss'] = [
                'TITLE' => Lang::t('RSS feed'),
                'NAME' => Lang::t('Notification'),
                'URL' => ServiceLocator::get(UrlGenerator::class)->notification(),
                'REL' => 'rel="nofollow"',
            ];
            $block->template = 'menubar_menu.tpl';
        }

        if (PermissionService::get()->isAGuest()) {
            $template->assign([
                'U_LOGIN' => ServiceLocator::get(UrlGenerator::class)->identification(),
                'U_LOST_PASSWORD' => ServiceLocator::get(UrlGenerator::class)->password(),
                'AUTHORIZE_REMEMBERING' => Config::authorizeRemembering(),
            ]);
            if (Config::allowUserRegistration()) {
                $template->assign('U_REGISTER', ServiceLocator::get(UrlGenerator::class)->register());
            }
        } else {
            $template->assign('USERNAME', stripslashes(CurrentUser::get()->username));
            if (PermissionService::get()->isAutorizeStatus(AccessLevel::Classic)) {
                $template->assign('U_PROFILE', ServiceLocator::get(UrlGenerator::class)->profile());
            }
            if (!Config::apacheAuthentication()) {
                $template->assign('U_LOGOUT', UrlService::getRootUrl() . '?act=logout');
            }
            if (PermissionService::get()->isAdmin()) {
                $template->assign('U_ADMIN', ServiceLocator::get(UrlGenerator::class)->admin());
            }
        }
        if (($block = $menu->getBlock('mbIdentification')) != null) {
            $block->template = 'menubar_identification.tpl';
        }
        $menu->apply('MENUBAR', 'menubar.tpl');
    }
}
