<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Latte\Runtime\Html;
use Piwigo\Admin\Updates;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
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

        $template->setFilenames(['tail' => 'footer.latte']);

        EventDispatcher::notify('loc_begin_page_tail');

        $template->assign([
            'VERSION'    => Config::showVersion() ? AppInfo::VERSION : '',
            'PHPWG_URL'  => defined('PHPWG_URL') ? str_replace('http:', 'https:', PHPWG_URL) : '',
        ]);

        if (!PermissionService::get()->isAGuest()) {
            $template->assign('CONTACT_MAIL', ServiceLocator::get(Util::class)->getWebmasterMailAddress());
        }

        if (Config::updateNotifyCheckPeriod() > 0) {
            $check_for_updates = !Config::has('update_notify_last_check')
                || strtotime((string) Config::updateNotifyLastCheck()) < strtotime(Config::updateNotifyCheckPeriod() . ' seconds ago');

            if ($check_for_updates) {
                $exec_id = ServiceLocator::get(Util::class)->pwgUniqueExecBegins('check_for_updates');
                if ($exec_id !== false) {
                    new Updates()->notifyPiwigoNewVersions();
                    ServiceLocator::get(Util::class)->pwgUniqueExecEnds('check_for_updates');
                }
            }
        }

        ServiceLocator::get(Util::class)->sendPiwigoInfos();

        $debug_vars = [];

        if (Config::showQueries()) {
            $debug = $GLOBALS['debug'] ?? '';
            $debug_vars['QUERIES_LIST'] = new Html(is_string($debug) ? $debug : '');
        }

        if (Config::showGt()) {
            $pageState  = PageState::current();
            $t2         = is_float($GLOBALS['t2'] ?? null) ? $GLOBALS['t2'] : microtime(true);
            $debug_vars += [
                'TIME'       => StringUtil::get()->getElapsedTime($t2, StringUtil::get()->getMoment()),
                'NB_QUERIES' => $pageState->countQueries,
                'SQL_TIME'   => number_format($pageState->queriesTime, 3, '.', ' ') . ' s',
            ];
        }

        $template->assign('debug', $debug_vars);

        if (!empty(Config::mobilTheme()) && (ServiceLocator::get(Util::class)->getDevice() !== 'desktop' || ServiceLocator::get(Util::class)->mobileTheme())) {
            /** @var mixed $requestUriRaw */
            $requestUriRaw = $_SERVER['REQUEST_URI'] ?? '';
            $template->assign('TOGGLE_MOBILE_THEME_URL', UrlService::get()->addUrlParams(
                htmlspecialchars(is_string($requestUriRaw) ? $requestUriRaw : ''),
                ['mobile' => ServiceLocator::get(Util::class)->mobileTheme() ? 'false' : 'true']
            ));
        }

        EventDispatcher::notify('loc_end_page_tail');

        $template->parse('tail');
        $template->p();
    }
}
