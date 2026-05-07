<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Admin\Updates;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\PageState;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;

final class PageTailRenderer
{
    public static function render(): void
    {
        $template = TemplateRegistry::current();

        $template->setFilenames(['tail' => 'footer.tpl']);

        EventDispatcher::notify('loc_begin_page_tail');

        $template->assign([
            'VERSION'    => Config::showVersion() ? AppInfo::VERSION : '',
            'PHPWG_URL'  => defined('PHPWG_URL') ? str_replace('http:', 'https:', PHPWG_URL) : '',
        ]);

        if (!PermissionService::get()->isAGuest()) {
            $template->assign('CONTACT_MAIL', get_webmaster_mail_address());
        }

        if (Config::updateNotifyCheckPeriod() > 0) {
            $check_for_updates = !Config::has('update_notify_last_check')
                || strtotime((string) Config::updateNotifyLastCheck()) < strtotime(Config::updateNotifyCheckPeriod() . ' seconds ago');

            if ($check_for_updates) {
                $exec_id = pwg_unique_exec_begins('check_for_updates');
                if ($exec_id !== false) {
                    new Updates()->notifyPiwigoNewVersions();
                    pwg_unique_exec_ends('check_for_updates');
                }
            }
        }

        send_piwigo_infos();

        $debug_vars = [];

        if (Config::showQueries()) {
            $debug = $GLOBALS['debug'] ?? '';
            $debug_vars['QUERIES_LIST'] = $debug;
        }

        if (Config::showGt()) {
            $pageState  = PageState::current();
            $t2         = is_float($GLOBALS['t2'] ?? null) ? $GLOBALS['t2'] : microtime(true);
            $debug_vars += [
                'TIME'       => get_elapsed_time($t2, get_moment()),
                'NB_QUERIES' => $pageState->countQueries,
                'SQL_TIME'   => number_format($pageState->queriesTime, 3, '.', ' ') . ' s',
            ];
        }

        $template->assign('debug', $debug_vars);

        if (!empty(Config::mobilTheme()) && (get_device() !== 'desktop' || mobile_theme())) {
            $template->assign('TOGGLE_MOBILE_THEME_URL', UrlService::get()->addUrlParams(
                htmlspecialchars(is_scalar($_SERVER['REQUEST_URI'] ?? null) ? (string) $_SERVER['REQUEST_URI'] : ''),
                ['mobile' => mobile_theme() ? 'false' : 'true']
            ));
        }

        EventDispatcher::notify('loc_end_page_tail');

        $template->parse('tail');
        $template->p();
    }
}
