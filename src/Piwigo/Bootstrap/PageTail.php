<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\PiwigoInfosSender;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\UniqueExecLock;
use Piwigo\Html\HtmlService;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Url\UrlService;

/**
 * The page-footer orchestration of the deleted include/page_tail.php (P23
 * sub-batch 8f-5), ported verbatim: the "check for Piwigo updates"
 * notification block, then the PageTailRenderer render itself.
 *
 * Lives in Bootstrap (L4) for the same reason the deleted seam existed at
 * all: the update check constructs Piwigo\Admin\Extensions\CoreUpdateService
 * and the renderer needs the concrete Piwigo\Admin\PiwigoInfosSender behind its
 * Piwigo\Core\TelemetrySenderInterface constructor param — both
 * L4Integration, which PageTailRenderer (L3Presentation) may not reach
 * (confirmed via a real deptrac violation when tried; see
 * PageTailRenderer's own docblock). Bootstrap shares L4 with
 * Admin/Controller, so this is the violation-free single home — same
 * "whole orchestration lives where the highest-layer dependency does"
 * reasoning as UserBootstrap.
 *
 * Every former `include PHPWG_ROOT_PATH . 'include/page_tail.php';` site
 * (the P22 controllers, admin.php, redirect_html()) calls
 * PageTail::render() instead; the request-start instant the seam captures
 * into `global $t2` is read here from PageState (Legacy Coupling
 * Retirement Track A gap-fill batch G5), so call sites need no bootstrap
 * variable of their own.
 */
final class PageTail
{
    public static function render(): void
    {
        // ----------------------------------------------- update notification
        $update_notify_check_period = \Piwigo\Config\Config::updateNotifyCheckPeriod();
        if (is_int($update_notify_check_period) && $update_notify_check_period > 0) {
            $check_for_updates = false;

            $update_notify_last_check = \Piwigo\Config\Config::updateNotifyLastCheck() ?? null;
            $update_notify_last_check = is_string($update_notify_last_check) ? $update_notify_last_check : null;

            if ($update_notify_last_check !== null) {
                if (strtotime($update_notify_last_check) < strtotime($update_notify_check_period . ' seconds ago')) {
                    $check_for_updates = true;
                }
            } else {
                $check_for_updates = true;
            }

            if ($check_for_updates) {
                $exec_id = UniqueExecLock::begins('check_for_updates');
                if ($exec_id !== false) {
                    new CoreUpdateService(new ZipExtractor(), new RedirectService(), new UrlService(new HtmlService()), CurrentConfigService::get())
                        ->notifyPiwigoNewVersions();

                    UniqueExecLock::ends('check_for_updates');
                }
            }
        }

        // P23 batch 8f-4: PageTailRenderer (L3) receives the telemetry
        // sender through Piwigo\Core\TelemetrySenderInterface -- this class
        // (L4) is the one place the concrete L4 implementation gets
        // constructed. Legacy Coupling Retirement Phase 4c: UrlServiceInterface
        // is wired the same way, see PageTailRenderer's own docblock.
        new PageTailRenderer(new PiwigoInfosSender(), new UrlService(new HtmlService()))
            ->render(\Piwigo\Core\PageState::current()->requestStart);
    }
}
