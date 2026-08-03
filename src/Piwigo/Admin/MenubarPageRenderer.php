<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Menu\BlockManager;

/**
 * Ported from admin/menubar.php (page slug "menubar").
 *
 * Real bug fixed during this port: the original file wrote
 * `$page['warnings'][] = ...` (the "webmaster status required" notice)
 * without `$page` in its own declared-globals list -- it only worked
 * before P21 because `admin.php` did a raw top-level `include`, sharing
 * scope directly. Once wrapped inside AdminDispatcher::dispatch()'s (or
 * MenubarSubController::handle()'s) own method scope, that write silently
 * landed in a method-local variable discarded on return -- a non-webmaster
 * admin editing this page never saw the warning. Retargeting onto
 * PageState::current() (Legacy Coupling Retirement Track A batch A5)
 * structurally closes off this whole bug class here: no `global`
 * declaration is needed to reach it from any scope.
 */
final class MenubarPageRenderer
{
    public function render(UrlServiceInterface $urlService, CoreTabs $coreTabs, \Piwigo\PluginConfig\EventDispatcher $eventDispatcher): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        // Legacy Coupling Retirement Phase 8, 8g: real, previously-unfixed
        // bug -- nothing had ever called CoreTabs::setContext() with
        // myBaseUrl for this page (same class of gap as
        // ConfigurationSubController's own $conf_link fix), so this page's
        // own tab strip has always rendered a broken relative href.
        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('menus');
        $tabsheet->select('');
        $tabsheet->assign();

        $menu = new BlockManager('menubar', $eventDispatcher);
        $menu->load_registered_blocks();
        $reg_blocks = $menu->get_registered_blocks();

        // blk_menubar is the only real BlockManager id anywhere in this
        // codebase (confirmed by grepping every `new BlockManager(...)`
        // call site) -- a real CurrentConfig property instead of the
        // former dynamic 'blk_' . $id bag key. Already decoded -- no
        // manual unserialize() needed (gap-closure Stage 1a-bis item 1).
        $mb_conf = \Piwigo\Config\CurrentConfig::blkMenubar() ?? [];

        // $mb_conf comes from DB-stored config, so its element types are
        // not statically known; normalize every position to a real int.
        $mb_conf_normalized = [];
        foreach ($mb_conf as $id => $pos) {
            $mb_conf_normalized[$id] = is_numeric($pos) ? (int) $pos : 0;
        }
        $mb_conf = $mb_conf_normalized;

        foreach ($mb_conf as $id => $pos) {
            if (! isset($reg_blocks[$id])) {
                unset($mb_conf[$id]);
            }
        }

        $idx = 1;
        foreach ($reg_blocks as $id => $block) {
            if (! isset($mb_conf[$id])) {
                $mb_conf[$id] = $idx * 50;
            }
            $idx++;
        }

        $menubarSubmit = Request\MenubarSubmitRequest::fromGlobals();
        if ($menubarSubmit->isSubmitted and \Piwigo\Auth\AccessControl::isWebmaster()) {
            foreach ($mb_conf as $id => $pos) {
                $hide = $menubarSubmit->isHidden($id);
                $mb_conf[$id] = ($hide ? -1 : +1) * abs($pos);

                $pos = $menubarSubmit->positionFor($id);
                if ($pos > 0) {
                    $mb_conf[$id] = $mb_conf[$id] > 0 ? $pos : -$pos;
                }
            }
            self::makeConsecutive($mb_conf);

            $mb_conf_db = $mb_conf;
            $encodedPositions = json_encode($mb_conf_db);
            assert($encodedPositions !== false);
            \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Config\ConfigEntry::class)
                ->upsert('blk_' . $menu->get_id(), $encodedPositions);

            // saveLayout() used to do this -- bypasses ConfigService::
            // confUpdateParam() entirely (no DI dependency here), so its
            // own cache-clearing never fires; without this,
            // ConfigService::allRowsFromCacheOrDb() would keep serving the
            // pre-save layout to every request until some unrelated config
            // write happened to clear the pool.
            \Piwigo\Cache\CachePools::config()->clear();

            $template->assign(
                [
                    'save_success' => Lang::t('Order of menubar items has been updated successfully.'),
                ]
            );
        }

        self::makeConsecutive($mb_conf);

        foreach ($mb_conf as $id => $pos) {
            $template->append(
                'blocks',
                [
                    'pos' => $pos / 5,
                    'reg' => $reg_blocks[$id],
                ]
            );
        }

        $action = $urlService->getRootUrl() . 'admin.php?page=menubar';
        $template->assign([
            'F_ACTION' => $action,
        ]);

        $template->assign('isWebmaster', \Piwigo\Auth\AccessControl::isWebmaster() ? 1 : 0);
        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Menu Management'));

        $template->set_filename('menubar_admin_content', 'menubar.tpl');
        $template->assign_var_from_handle('ADMIN_CONTENT', 'menubar_admin_content');
    }

    /**
     * Sort key for {@see makeConsecutive()}'s uasort() -- orders by
     * magnitude, preserving each entry's own sign.
     */
    public static function absFnCmp(int $a, int $b): int
    {
        return abs($a) - abs($b);
    }

    /**
     * Renumbers menubar item positions to consecutive multiples of $step,
     * preserving each entry's sign (negative = hidden, positive = shown) and
     * relative order.
     *
     * @param  array<int|string, int>  $orders
     */
    public static function makeConsecutive(array &$orders, int $step = 50): void
    {
        uasort($orders, self::absFnCmp(...));
        $crt = 1;
        foreach ($orders as $id => $pos) {
            $orders[$id] = $step * ($pos < 0 ? -$crt : $crt);
            $crt++;
        }
    }
}
