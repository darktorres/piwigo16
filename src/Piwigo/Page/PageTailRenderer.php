<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Doctrine\ORM\EntityManagerInterface;
use Latte\Runtime\Html;
use Piwigo\Asset\ViteManifest;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\DeviceHelper;
use Piwigo\Core\RequestMetrics;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Page\Event\PageTailRendered;
use Piwigo\Page\Event\PageTailRendering;
use Piwigo\Page\Projection\DebugInfo;
use Piwigo\Page\Projection\PageTailPageContext;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\UserRepository;

/**
 * Renders the page footer into $template.
 *
 * The "check for Piwigo updates" block is not rendered here: layering
 * rules disallow this class's layer (L3 Presentation) from depending on
 * Admin (L4 Integration). That block lives in
 * Piwigo\Bootstrap\PageTail::prepareContext(), which runs it right
 * before constructing this renderer.
 *
 * The telemetry send has the same layering constraint, so it's injected
 * as Piwigo\Core\TelemetrySenderInterface rather than called directly;
 * this class's own construction site (Bootstrap\PageTail::prepareContext())
 * passes the concrete Piwigo\Admin\PiwigoInfosSender.
 *
 * UrlServiceInterface is also real constructor injection here, unlike
 * Html\HtmlService/Mail\MailService/Users\UserService/Template\Template/
 * PageHeaderRenderer's throwaway-per-call pattern: this class is not
 * reachable from Piwigo\Bootstrap\RedirectService's construction chain,
 * so wiring it through the constructor here doesn't risk circularity.
 */
final readonly class PageTailRenderer
{
    public function __construct(
        private AccessLevelChecker $accessLevelChecker,
        private TelemetrySenderInterface $telemetrySender,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private RequestMetrics $requestMetrics,
        private CurrentTemplate $currentTemplate,
        private CurrentConfig $currentConfig,
        private SessionService $sessionService,
        private EntityManagerInterface $entityManager,
        private ViteManifest $viteManifest,
    ) {}

    /**
     * The context-building half of the old parse()-calling
     * renderToString() (deleted, P41-E, docs/PLAN.md) -- everything a
     * `{layout}`-based caller needs before rendering its own
     * page-specific `View` through `Renderer::render()` and
     * `Template::finalizeHtml()` in one shot.
     */
    public function prepareContext(float $startTime): void
    {
        $template = $this->currentTemplate->get();

        $this->eventDispatcher->dispatch(new PageTailRendering());

        $contactMail = null;
        if (! $this->accessLevelChecker->isAGuest()) {
            $contactMail = new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig)
                ->getWebmasterMailAddress();
        }

        $this->telemetrySender->send();

        $queries_list = $this->currentConfig->showQueries ? new Html($this->requestMetrics->debugOutput) : null;

        $time = null;
        $count_queries = null;
        $sql_time = null;
        if ($this->currentConfig->showGt) {
            $count_queries = $this->requestMetrics->countQueries;
            $time = TimingHelper::getElapsedTime($startTime, TimingHelper::getMoment());
            $sql_time = number_format($this->requestMetrics->queriesTime, 3, '.', ' ') . ' s';
        }

        $debug_vars = new DebugInfo(
            queriesList: $queries_list,
            time: $time,
            nbQueries: $count_queries,
            sqlTime: $sql_time,
        );

        $toggleMobileThemeUrl = null;
        if (! self::emptyValue($this->currentConfig->mobileTheme) && (DeviceHelper::getDevice($this->sessionService) !== 'desktop' || DeviceHelper::mobileTheme($this->sessionService, $this->currentConfig))) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $request_uri = is_string($request_uri) ? $request_uri : '';
            // Not pre-escaped (P59 Batch 5): toggleMobileThemeUrl reaches
            // layout.latte as a bare {$TOGGLE_MOBILE_THEME_URL} print, where
            // Latte's own auto-escape does the entity-encoding once, at
            // print time -- htmlspecialchars()'ing $request_uri here too
            // double-escaped it, corrupting the toggle link on any request
            // whose query string carried more than one param.
            $toggleMobileThemeUrl = $this->urlService->addUrlParams(
                $request_uri,
                [
                    'mobile' => DeviceHelper::mobileTheme($this->sessionService, $this->currentConfig) ? 'false' : 'true',
                ]
            );
        }

        $vitalsEntry = $this->viteManifest->resolve('build/vitals.ts');

        $template->assignContext(new PageTailPageContext(
            version: $this->currentConfig->showVersion ? AppInfo::VERSION : '',
            phpwgUrl: AppInfo::URL,
            // web-vitals RUM beacon. `vitals.js` is a fixed, non-hashed
            // filename (vite.config.ts), so this doesn't strictly need a
            // manifest.json lookup -- resolved through ViteManifest
            // anyway (docs/PLAN.md's P36 section), proving the
            // manifest-reading half end to end against the one real
            // entry that exists today. Falls back to the same literal
            // path a missing/malformed manifest already degraded to
            // before this.
            vitalsScriptUrl: $this->urlService->getRootUrl() . 'dist/' . ($vitalsEntry !== null ? $vitalsEntry->file : 'vitals.js'),
            contactMail: $contactMail,
            debug: $debug_vars,
            toggleMobileThemeUrl: $toggleMobileThemeUrl,
        ));

        $this->eventDispatcher->dispatch(new PageTailRendered());
    }

    /**
     * Matches empty()'s exact truthiness semantics -- required since
     * empty() itself is disallowed by this project's strict PHPStan rules.
     */
    private static function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === 0 || $value === 0.0 || $value === '0' || $value === false || $value === [];
    }
}
