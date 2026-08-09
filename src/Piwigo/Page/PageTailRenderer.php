<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AppInfo;
use Piwigo\Core\DeviceHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Location\LocBeginPageTail;
use Piwigo\Event\Location\LocEndPageTail;
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
    ) {}

    public function render(float $startTime): void
    {
        $this->prepareTail($startTime);
        $this->currentTemplate->get()
            ->p();
    }

    /**
     * The non-echoing sibling of render(): same orchestration, but
     * returns the fully rendered page (everything accumulated in
     * $template->output so far, header/content/tail together, see
     * Template::fetchOutput()'s own docblock) instead of sending it to
     * the browser. For controllers returning a real PSR-7 Response
     * instead of echoing directly.
     */
    public function renderToString(float $startTime): string
    {
        $this->prepareTail($startTime);
        return $this->currentTemplate->get()
            ->fetchOutput();
    }

    private function prepareTail(float $startTime): void
    {
        $template = $this->currentTemplate->get();

        $template->set_filenames([
            'tail' => 'footer.tpl',
        ]);

        $this->eventDispatcher->dispatchNotify(new LocBeginPageTail());

        // --------------------------------------------------------------------- contact

        $contactMail = null;
        if (! $this->accessLevelChecker->isAGuest()) {
            $contactMail = (new UserRepository(EntityManagerFactory::build(DbConnection::build()), $this->eventDispatcher, $this->currentConfig))->getWebmasterMailAddress();
        }

        $this->telemetrySender->send();

        // ------------------------------------------------------------- generation time
        $debug_vars = [];

        if ($this->currentConfig->showQueries()) {
            $debug_vars = array_merge($debug_vars, [
                'QUERIES_LIST' => $this->pageState->debugOutput,
            ]);
        }

        if ($this->currentConfig->showGt()) {
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

        // ------------------------------------------------------------- mobile version
        $toggleMobileThemeUrl = null;
        if (! self::emptyValue($this->currentConfig->mobilTheme()) && (DeviceHelper::getDevice($this->sessionService) !== 'desktop' || DeviceHelper::mobileTheme($this->sessionService, $this->currentConfig))) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $toggleMobileThemeUrl = $this->urlService->addUrlParams(
                htmlspecialchars(is_string($request_uri) ? $request_uri : ''),
                [
                    'mobile' => DeviceHelper::mobileTheme($this->sessionService, $this->currentConfig) ? 'false' : 'true',
                ]
            );
        }

        $template->assignContext(new PageTailPageContext(
            version: $this->currentConfig->showVersion() ? AppInfo::VERSION : '',
            phpwgUrl: AppInfo::URL,
            // web-vitals RUM beacon -- fixed,
            // non-hashed filename (vite.config.ts), so no manifest.json
            // lookup is needed to reference it.
            vitalsScriptUrl: $this->urlService->getRootUrl() . 'dist/vitals.js',
            contactMail: $contactMail,
            debug: $debug_vars,
            toggleMobileThemeUrl: $toggleMobileThemeUrl,
        ));

        $this->eventDispatcher->dispatchNotify(new LocEndPageTail());
        //
        // Generate the page
        //
        $template->parse('tail');
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
