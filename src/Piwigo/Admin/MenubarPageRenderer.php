<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\MenubarView;
use Piwigo\Admin\Request\MenubarSubmitRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\ConfigCachePool;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Menu\BlockManager;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;

/**
 * Ported from admin/menubar.php (page slug "menubar").
 *
 * The "webmaster status required" notice is written through the
 * constructor-injected PageState, not a `global $page['warnings']` write --
 * no `global` declaration is needed to reach it from any scope.
 */
final class MenubarPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CoreTabs $coreTabs, EventDispatcher $eventDispatcher, PageState $pageState, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, EntityManagerInterface $entityManager, ConfigCachePool $configCachePool, Renderer $renderer): void
    {
        $template = $currentTemplate->get();

        if (! $accessControl->isWebmaster()) {
            $pageState->addWarning(str_replace('%s', $lang->t('user_status_webmaster'), $lang->t('%s status is required to edit parameters.')));
        }

        // CoreTabs::setContext() must be called with myBaseUrl, or this
        // page's tab strip renders a broken relative href.
        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));
        $tabsheet = new Tabsheet();
        $tabsheet->setId('menus');
        $tabsheet->select('', $eventDispatcher);
        $tabsheet->assign($currentTemplate, $renderer);

        $menu = new BlockManager('menubar', $eventDispatcher, $currentTemplate, $currentConfig, $renderer);
        $menu->loadRegisteredBlocks();
        $reg_blocks = $menu->getRegisteredBlocks();

        // blk_menubar is the only real BlockManager id anywhere in this
        // codebase -- a real CurrentConfig property instead of the
        // former dynamic 'blk_' . $id bag key. Already decoded -- no
        // manual unserialize() needed.
        // Every position is already a real int -- blkMenubar's own
        // sanitizing hook drops non-numeric entries rather than coercing
        // them, so no further normalization is needed here.
        $mb_conf = $currentConfig->blkMenubar ?? [];

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

        $save_success = null;
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
            $entityManager->getRepository(ConfigEntry::class)
                ->upsert('blk_' . $menu->getId(), $encodedPositions);

            // The upsert() above bypasses ConfigService::confUpdateParam()
            // entirely (no DI dependency here), so its own cache-clearing
            // never fires; without this explicit clear,
            // ConfigService::allRowsFromCacheOrDb() would keep serving the
            // pre-save layout to every request until some unrelated config
            // write happened to clear the pool.
            $configCachePool->clear();

            $save_success = $lang->t('Order of menubar items has been updated successfully.');
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
        $adminContent = $renderer->render(new MenubarView(
            formAction: $action,
            isWebmaster: $accessControl->isWebmaster() ? 1 : 0,
            blocks: $blocks,
            saveSuccess: $save_success,
        ));

        $template->assignContext(new AdminContentPageContext(
            adminContent: $adminContent,
            adminPageTitle: $lang->t('Menu Management'),
        ));
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
