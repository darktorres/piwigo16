<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Latte\Runtime\Html;
use Piwigo\Admin\Updates;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;

final class PageTailRenderer
{
    public static function render(): void
    {
        $template = TemplateRegistry::current();

        EventDispatcher::notify('loc_begin_page_tail');

        $template->assign([
            'VERSION'    => Config::showVersion() ? AppInfo::VERSION : '',
            'PHPWG_URL'  => defined('PHPWG_URL') ? str_replace('http:', 'https:', PHPWG_URL) : '',
        ]);

        if (!Kernel::service(PermissionService::class)->isAGuest()) {
            $template->assign('CONTACT_MAIL', Kernel::service(Util::class)->getWebmasterMailAddress());
        }

        if (Config::updateNotifyCheckPeriod() > 0) {
            $check_for_updates = !Config::has('update_notify_last_check')
                || strtotime((string) Config::updateNotifyLastCheck()) < strtotime(Config::updateNotifyCheckPeriod() . ' seconds ago');

            if ($check_for_updates) {
                $exec_id = Kernel::service(Util::class)->pwgUniqueExecBegins('check_for_updates');
                if ($exec_id !== false) {
                    Kernel::service(Updates::class)->notifyPiwigoNewVersions();
                    Kernel::service(Util::class)->pwgUniqueExecEnds('check_for_updates');
                }
            }
        }

        Kernel::service(Util::class)->sendPiwigoInfos();

        $debug_vars = [];

        if (Config::showQueries()) {
            $debug_vars['QUERIES_LIST'] = new Html(implode('', PageState::current()->debugLines));
        }

        if (Config::showGt()) {
            $pageState  = PageState::current();
            $t2         = is_numeric($_SERVER['REQUEST_TIME_FLOAT'] ?? null) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
            $debug_vars += [
                'TIME'       => Kernel::service(StringUtil::class)->getElapsedTime($t2, Kernel::service(StringUtil::class)->getMoment()),
                'NB_QUERIES' => $pageState->countQueries,
                'SQL_TIME'   => number_format($pageState->queriesTime, 3, '.', ' ') . ' s',
            ];
        }

        $template->assign('debug', $debug_vars);

        if (!empty(Config::mobilTheme()) && (Kernel::service(Util::class)->getDevice() !== 'desktop' || Kernel::service(Util::class)->mobileTheme())) {
            /** @var mixed $requestUriRaw */
            $requestUriRaw = $_SERVER['REQUEST_URI'] ?? '';
            $template->assign('TOGGLE_MOBILE_THEME_URL', Kernel::service(UrlService::class)->addUrlParams(
                htmlspecialchars(is_string($requestUriRaw) ? $requestUriRaw : ''),
                ['mobile' => Kernel::service(Util::class)->mobileTheme() ? 'false' : 'true']
            ));
        }

        EventDispatcher::notify('loc_end_page_tail');

        $template->parse('footer.latte');
        $template->flush();
    }
}
