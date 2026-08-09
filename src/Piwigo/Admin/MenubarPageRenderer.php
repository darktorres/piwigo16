<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\MenubarPageContext;
use Piwigo\Admin\Projection\MenubarSaveSuccessPageContext;
use Piwigo\Admin\Request\MenubarSubmitRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\CachePools;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Menu\BlockManager;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;

/**
 * Ported from admin/menubar.php (page slug "menubar").
 *
 * The "webmaster status required" notice is written through the
 * constructor-injected PageState, not a `global $page['warnings']` write --
 * no `global` declaration is needed to reach it from any scope.
 */
final class MenubarPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CoreTabs $coreTabs, EventDispatcher $eventDispatcher, PageState $pageState, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig): void
    {
        $template = $currentTemplate->get();

        if (! $accessControl->isWebmaster()) {
            $pageState->addWarning(str_replace('%s', $lang->t('user_status_webmaster'), $lang->t('%s status is required to edit parameters.')));
        }

        // CoreTabs::setContext() must be called with myBaseUrl, or this
        // page's tab strip renders a broken relative href.
        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->set_id('menus');
        $tabsheet->select('', $eventDispatcher);
        $tabsheet->assign($currentTemplate);

        $menu = new BlockManager('menubar', $eventDispatcher, $currentTemplate, $currentConfig);
        $menu->load_registered_blocks();
        $reg_blocks = $menu->get_registered_blocks();

        // blk_menubar is the only real BlockManager id anywhere in this
        // codebase (confirmed by grepping every `new BlockManager(...)`
        // call site) -- a real CurrentConfig property instead of the
        // former dynamic 'blk_' . $id bag key. Already decoded -- no
        // manual unserialize() needed (gap-closure Stage 1a-bis item 1).
        $mb_conf = $currentConfig->blkMenubar() ?? [];

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

        $menubarSubmit = MenubarSubmitRequest::fromGlobals();
        if ($menubarSubmit->isSubmitted and $accessControl->isWebmaster()) {
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
            EntityManagerFactory::build(DbConnection::build())->getRepository(ConfigEntry::class)
                ->upsert('blk_' . $menu->get_id(), $encodedPositions);

            // saveLayout() used to do this -- bypasses ConfigService::
            // confUpdateParam() entirely (no DI dependency here), so its
            // own cache-clearing never fires; without this,
            // ConfigService::allRowsFromCacheOrDb() would keep serving the
            // pre-save layout to every request until some unrelated config
            // write happened to clear the pool.
            CachePools::config()->clear();

            $template->assignContext(new MenubarSaveSuccessPageContext($lang->t('Order of menubar items has been updated successfully.')));
        }

        self::makeConsecutive($mb_conf);

        $blocks = [];
        foreach ($mb_conf as $id => $pos) {
            $blocks[] = [
                'pos' => $pos / 5,
                'reg' => $reg_blocks[$id],
            ];
        }

        $action = $urlService->getRootUrl() . 'admin.php?page=menubar';
        $template->assignContext(new MenubarPageContext(
            formAction: $action,
            isWebmaster: $accessControl->isWebmaster(),
            adminPageTitle: $lang->t('Menu Management'),
            blocks: $blocks,
        ));

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
