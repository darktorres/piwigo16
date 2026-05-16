<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Latte\Runtime\Html;
use Piwigo\Admin\Updates;
use Piwigo\Config\Config;
use Piwigo\Core\AppInfo;
use Piwigo\Core\ExecutionMutex;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Event\Location\LocBeginPageTail;
use Piwigo\Event\Location\LocEndPageTail;
use Piwigo\Http\DeviceDetectionService;
use Piwigo\Mail\MailService;
use Piwigo\Telemetry\TelemetryService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Psr\EventDispatcher\EventDispatcherInterface;

final class PageTailRenderer
{
    public static function render(): void
    {
        $template = TemplateRegistry::current();

        $dispatcher = Kernel::service(EventDispatcherInterface::class);
        $dispatcher->dispatch(new LocBeginPageTail());

        $template->assign([
            'VERSION'    => Config::showVersion() ? AppInfo::VERSION : '',
            'PHPWG_URL'  => defined('PHPWG_URL') ? str_replace('http:', 'https:', PHPWG_URL) : '',
        ]);

        if (!Kernel::service(PermissionService::class)->isAGuest()) {
            $template->assign('CONTACT_MAIL', Kernel::service(MailService::class)->getWebmasterMailAddress());
        }

        if (Config::updateNotifyCheckPeriod() > 0) {
            $check_for_updates = !Config::has('update_notify_last_check')
                || strtotime((string) Config::updateNotifyLastCheck()) < strtotime(Config::updateNotifyCheckPeriod() . ' seconds ago');

            if ($check_for_updates) {
                $mutex   = Kernel::service(ExecutionMutex::class);
                $exec_id = $mutex->acquire('check_for_updates');
                if ($exec_id !== false) {
                    Kernel::service(Updates::class)->notifyPiwigoNewVersions();
                    $mutex->release('check_for_updates');
                }
            }
        }

        Kernel::service(TelemetryService::class)->sendInfos();

        $debug_vars = [];

        if (Config::showQueries()) {
            $debug_vars['QUERIES_LIST'] = new Html(implode('', PageState::current()->debugLines));
        }

        if (Config::showGt()) {
            $pageState  = PageState::current();
            $reqTimeFloat = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
            $t2           = is_float($reqTimeFloat) ? $reqTimeFloat : microtime(true);
            $debug_vars += [
                'TIME'       => StringUtil::getElapsedTime($t2, StringUtil::getMoment()),
                'NB_QUERIES' => $pageState->countQueries,
                'SQL_TIME'   => number_format($pageState->queriesTime, 3, '.', ' ') . ' s',
            ];
        }

        $template->assign('debug', $debug_vars);

        $deviceService = Kernel::service(DeviceDetectionService::class);
        if (!empty(Config::mobilTheme()) && ($deviceService->getDevice() !== 'desktop' || $deviceService->isMobileTheme())) {
            /** @var mixed $requestUriRaw */
            $requestUriRaw = $_SERVER['REQUEST_URI'] ?? '';
            $template->assign('TOGGLE_MOBILE_THEME_URL', Kernel::service(UrlService::class)->addUrlParams(
                htmlspecialchars(is_string($requestUriRaw) ? $requestUriRaw : ''),
                ['mobile' => $deviceService->isMobileTheme() ? 'false' : 'true']
            ));
        }

        $dispatcher->dispatch(new LocEndPageTail());

        $template->parse('footer.latte');
        $template->flush();
    }
}
