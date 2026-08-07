<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\UpdatesExtPageRenderer;
use Piwigo\Admin\UpdatesPwgPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Request\UpdatesTabRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/updates.php's own tab-dispatch shell (page slug
 * "updates") -- same shape as `LanguagesSubController`/
 * `ThemesSubController`/`PluginsSubController`. Its own tab dispatch is
 * validated against `/^(pwg|ext)$/` before being used.
 *
 * `$my_base_url` is load-bearing, not dead code: `Tabsheet::select()`
 * fires a `tabsheet_before_select` event
 * (`Piwigo\Admin\CoreTabs::addCoreTabs()`), whose own
 * `case 'updates':`/`'languages':`/`'themes':`/`'plugins':`/`'maintenance':`
 * branches each read `global $my_base_url;` to build every tab's own href.
 * Dropping it silently degrades those hrefs (concatenating onto an
 * undefined-then-null global coerces to `''`, e.g. `&amp;tab=installed`
 * instead of `admin.php?page=languages&amp;tab=installed`) rather than
 * erroring.
 *
 * The 2 leaf files this dispatches to are
 * `Piwigo\Admin\UpdatesPwgPageRenderer`/`UpdatesExtPageRenderer`.
 * `UpdatesExtPageRenderer` is a shared class also called directly by
 * `LanguagesSubController`/`ThemesSubController`/`PluginsSubController`'s
 * own "update" tab. `updates.class.php` itself still exists --
 * install.php/upgrade.php/include/functions.inc.php's telemetry sender/
 * include/ws_functions/pwg.extensions.php all still construct it
 * directly, and none of those are admin pages.
 */
final class UpdatesSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly CoreTabs $coreTabs,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ExtensionUpdateChecker $extensionUpdateChecker,
        private readonly UpdatesPwgPageRenderer $updatesPwgPageRenderer,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly EventDispatcher $eventDispatcher,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        if (! $this->currentConfig->enableExtensionsInstall() and ! $this->currentConfig->enableCoreUpdate()) {
            $this->htmlRenderer
                ->fatalError('update system is disabled');
        }

        // Consumed by CoreTabs::addCoreTabs()'s own 'updates' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page=updates'));

        $tab = UpdatesTabRequest::fromGlobals($this->inputValidator)->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('updates');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        if ($tab === 'ext') {
            new UpdatesExtPageRenderer()
                ->render($this->lang, $this->accessControl, 'updates', $this->urlService, $this->configService, $this->pageState, $this->currentTemplate, $this->extensionUpdateChecker, $this->htmlRenderer, $this->currentConfig);
        } else {
            $this->updatesPwgPageRenderer
                ->render();
        }
    }
}
