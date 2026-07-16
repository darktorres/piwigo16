<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Db\DbConnection;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\MenubarLayoutRepository;
use Piwigo\Template\Template;

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
 * admin editing this page never saw the warning. `global $page;` below is
 * a real, load-bearing addition the original file never needed.
 */
final class MenubarPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $conf, $page, $template;

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            if (! is_array($page['warnings'] ?? null)) {
                $page['warnings'] = [];
            }
            $page['warnings'][] = str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'));
        }

        $tabsheet = new tabsheet();
        $tabsheet->set_id('menus');
        $tabsheet->select('');
        $tabsheet->assign();

        $menu = new BlockManager('menubar');
        $menu->load_registered_blocks();
        $reg_blocks = $menu->get_registered_blocks();

        $mb_conf = $conf['blk_' . $menu->get_id()] ?? null;
        if (is_string($mb_conf)) {
            $mb_conf = unserialize($mb_conf);
        }
        if (! is_array($mb_conf)) {
            $mb_conf = [];
        }

        // $mb_conf comes from an unserialize() of DB-stored config, so its element
        // types are not statically known; normalize every position to a real int.
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

        if (isset($_POST['submit']) and \Piwigo\Auth\AccessControl::isWebmaster()) {
            foreach ($mb_conf as $id => $pos) {
                $hide = isset($_POST['hide_' . $id]);
                $mb_conf[$id] = ($hide ? -1 : +1) * abs($pos);

                $pos_input = $_POST['pos_' . $id] ?? null;
                $pos = is_numeric($pos_input) ? (int) $pos_input : 0;
                if ($pos > 0) {
                    $mb_conf[$id] = $mb_conf[$id] > 0 ? $pos : -$pos;
                }
            }
            self::makeConsecutive($mb_conf);

            $mb_conf_db = $mb_conf;
            new MenubarLayoutRepository(DbConnection::build())->saveLayout($menu->get_id(), $mb_conf_db);

            $template->assign(
                [
                    'save_success' => l10n('Order of menubar items has been updated successfully.'),
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

        $action = get_root_url() . 'admin.php?page=menubar';
        $template->assign([
            'F_ACTION' => $action,
        ]);

        $template->assign('isWebmaster', \Piwigo\Auth\AccessControl::isWebmaster() ? 1 : 0);
        $template->assign('ADMIN_PAGE_TITLE', l10n('Menu Management'));

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
