<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Asset\ViteManifest;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\DeviceHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Page\Event\PageTailRendered;
use Piwigo\Page\Event\PageTailRendering;
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
 * Piwigo\Bootstrap\PageTail::render(), which runs it right before
 * constructing this renderer.
 *
 * The telemetry send has the same layering constraint, so it's injected
 * as Piwigo\Core\TelemetrySenderInterface rather than called directly;
 * both of this class's construction sites (Bootstrap\PageTail::render()
 * and Bootstrap\PageTail::renderToString()) pass the concrete
 * Piwigo\Admin\PiwigoInfosSender.
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
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private CurrentConfig $currentConfig,
        private SessionService $sessionService,
        private EntityManagerInterface $entityManager,
        private ViteManifest $viteManifest,
    ) {}

    /**
     * @deprecated P41 (docs/PLAN.md): calls prepareContext() then the
     *   old `Template::parse('footer.latte')`/`flush()` path. Real
     *   callers switch to `prepareContext()` + the new `{layout}`-based
     *   `Renderer::render()`/`finalizeHtml()` one at a time; this stays
     *   until every real caller has switched (P41-E deletes it).
     */
    public function render(float $startTime): void
    {
        $this->prepareContext($startTime);
        $template = $this->currentTemplate->get();
        $template->parse('footer.latte');
        $template->flush();
    }

    /**
     * The non-echoing sibling of render(): same orchestration, but
     * returns the fully rendered page (everything accumulated in
     * $template->output so far, header/content/tail together, see
     * Template::fetchOutput()'s own docblock) instead of sending it to
     * the browser. For controllers returning a real PSR-7 Response
     * instead of echoing directly.
     *
     * @deprecated P41 (docs/PLAN.md): see render()'s own docblock.
     */
    public function renderToString(float $startTime): string
    {
        $this->prepareContext($startTime);
        $template = $this->currentTemplate->get();
        $template->parse('footer.latte');
        return $template->fetchOutput();
    }

    /**
     * The context-building half of render()/renderToString() --
     * everything up to (not including) the actual template render, so a
     * `{layout}`-based caller (P41) can build this exact same ambient
     * context, then render its own page-specific `View` through
     * `Renderer::render()` and `Template::finalizeHtml()` in one shot
     * instead of a separate `parse('footer.latte')`/`flush()` call.
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

        $debug_vars = [];

        if ($this->currentConfig->showQueries) {
            $debug_vars = array_merge($debug_vars, [
                'QUERIES_LIST' => $this->pageState->debugOutput,
            ]);
        }

        if ($this->currentConfig->showGt) {
            $count_queries = $this->pageState->countQueries;
            $queries_time = $this->pageState->queriesTime;

            $time = TimingHelper::getElapsedTime($startTime, TimingHelper::getMoment());

            $debug_vars = array_merge(
                $debug_vars,
                [
                    'TIME' => $time,
                    'NB_QUERIES' => $count_queries,
                    'SQL_TIME' => number_format($queries_time, 3, '.', ' ') . ' s',
                ]
            );
        }

        $toggleMobileThemeUrl = null;
        if (! self::emptyValue($this->currentConfig->mobileTheme) && (DeviceHelper::getDevice($this->sessionService) !== 'desktop' || DeviceHelper::mobileTheme($this->sessionService, $this->currentConfig))) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $toggleMobileThemeUrl = $this->urlService->addUrlParams(
                htmlspecialchars(is_string($request_uri) ? $request_uri : ''),
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
