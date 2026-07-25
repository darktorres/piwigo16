<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\UpdatesExtPageRenderer;
use Piwigo\Admin\UpdatesPwgPageRenderer;
use Piwigo\Config\ConfigService;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/updates.php's own tab-dispatch shell (page slug
 * "updates"), folded directly into this controller (P23 sub-batch 6i-4) --
 * same shape as `LanguagesSubController`/`ThemesSubController`/
 * `PluginsSubController`. Its own tab dispatch is already validated
 * (`/^(pwg|ext)$/`) -- a prior remediation pass already fixed a real LFI
 * here (this ?tab= value was never validated before being spliced into
 * `include admin/updates_<tab>.php`, unlike the sibling clusters' own tab
 * dispatch), re-verified still correct during this port, not a new find.
 *
 * `$my_base_url` is NOT dead code, despite every prior 6h/6i-1/6i-2/6i-3
 * sub-batch's own shell-fold docblock claiming the same-shaped local var
 * was (confirmed via grep within each file's own scope, which is true --
 * but incomplete). It's a real, load-bearing global consumed indirectly:
 * `Tabsheet::select()` fires a `tabsheet_before_select` event
 * (`Piwigo\Admin\CoreTabs::addCoreTabs()`, formerly `admin/include/
 * add_core_tabs.inc.php`'s `add_core_tabs()`, folded in P23 batch 8b-6),
 * whose own
 * `case 'updates':`/`'languages':`/`'themes':`/`'plugins':`/`'maintenance':`
 * branches each read `global $my_base_url;` to build every tab's own href.
 * Dropping it silently degrades those hrefs (concatenating onto an
 * undefined-then-null global coerces to `''`, e.g. `&amp;tab=installed`
 * instead of `admin.php?page=languages&amp;tab=installed`) rather than
 * erroring -- confirmed live post-port that every one of the 4 prior
 * sub-batches' own tab links were broken this way; see the follow-up
 * correction commit fixing all 4 alongside this one.
 *
 * The 2 leaf files this dispatches to were migrated off the
 * updates.class.php god-class (already replaced by CoreUpdateService/
 * ExtensionUpdateChecker/PemCatalog/ExtensionScanner in a prior P21-era
 * pass) onto Piwigo\Admin\UpdatesPwgPageRenderer/UpdatesExtPageRenderer --
 * see UpdatesPwgPageRenderer's own docblock for the most severe CSRF gap
 * found across the whole P23 batch 6 effort, found and fixed here.
 * UpdatesExtPageRenderer is a shared class also called directly by
 * LanguagesSubController/ThemesSubController/PluginsSubController's own
 * "update" tab (previously each did its own raw
 * `include admin/updates_ext.php`). updates.class.php itself is NOT
 * deleted -- install.php/upgrade.php/include/functions.inc.php's
 * telemetry sender/include/ws_functions/pwg.extensions.php all still
 * construct it directly, and none of those are admin pages (P21's real
 * scope, re-verified still accurate).
 */
final class UpdatesSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        if (! \Piwigo\Config\CurrentConfig::enableExtensionsInstall() and ! \Piwigo\Config\CurrentConfig::enableCoreUpdate()) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('update system is disabled');
        }

        // Consumed by CoreTabs::addCoreTabs()'s own 'updates' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        CoreTabs::setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page=updates'));

        new \Piwigo\Validation\InputValidator()
            ->validate('tab', $_GET, false, '/^(pwg|ext)$/');
        if (isset($_GET['tab']) && is_string($_GET['tab'])) {
            $tab = $_GET['tab'];
        } else {
            $tab = 'pwg';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('updates');
        $tabsheet->select($tab);
        $tabsheet->assign();

        if ($tab === 'ext') {
            new UpdatesExtPageRenderer()
                ->render('updates', $this->urlService, $this->configService);
        } else {
            \Piwigo\Bootstrap\AdminAccessor::updatesPwgPageRenderer()
                ->render();
        }
    }
}
