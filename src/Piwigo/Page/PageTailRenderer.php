<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Core\AppInfo;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Core\UrlServiceInterface;

/**
 * Renders the page footer into $template.
 *
 * The original page_tail.php's "check for Piwigo updates" block
 * (constructing Piwigo\Admin\Extensions\CoreUpdateService) is deliberately
 * NOT ported here --
 * L3Presentation may not depend on L4Integration (Admin), confirmed via a
 * real deptrac violation when tried. It lives in
 * Piwigo\Bootstrap\PageTail::render() (L4, the orchestrator that replaced
 * the thin include/page_tail.php seam in P23 sub-batch 8f-5), which runs
 * it right before constructing this renderer.
 *
 * P23 batch 8f-4: the telemetry send (formerly the bare
 * send_piwigo_infos() free function, deleted with
 * include/functions.inc.php) has the exact same L3-may-not-reach-L4 shape
 * -- injected here as Piwigo\Core\TelemetrySenderInterface (constructor
 * injection: exactly one construction site, Bootstrap\PageTail::render(),
 * which passes the concrete Piwigo\Admin\PiwigoInfosSender).
 *
 * Legacy Coupling Retirement Phase 4c: UrlServiceInterface is also
 * real constructor injection here, unlike Html\HtmlService/
 * Mail\MailService/Users\UserService/Template\Template/
 * PageHeaderRenderer's throwaway-per-call pattern -- this class's own one
 * real construction site is Bootstrap\PageTail::render() itself, already
 * an established composition root manually wiring
 * TelemetrySenderInterface's concrete implementation; wiring a second
 * interface there the same way is consistent, not circular (unlike those
 * other classes, this one isn't reachable from
 * Piwigo\Bootstrap\RedirectService's own construction chain).
 */
final readonly class PageTailRenderer
{
    public function __construct(
        private TelemetrySenderInterface $telemetrySender,
        private UrlServiceInterface $urlService,
    ) {}

    public function render(float $startTime): void
    {
        $this->prepareTail($startTime);
        \Piwigo\Template\CurrentTemplate::get()
            ->p();
    }

    /**
     * Legacy Coupling Retirement Workstream D: the non-echoing sibling of
     * render() -- same orchestration, but returns the fully rendered page
     * (everything accumulated in $template->output so far, header/content/
     * tail together, see Template::fetchOutput()'s own docblock) instead
     * of sending it to the browser. For controllers returning a real
     * PSR-7 Response instead of echoing directly.
     */
    public function renderToString(float $startTime): string
    {
        $this->prepareTail($startTime);
        return \Piwigo\Template\CurrentTemplate::get()
            ->fetchOutput();
    }

    private function prepareTail(float $startTime): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $template->set_filenames([
            'tail' => 'footer.tpl',
        ]);

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_page_tail');

        $template->assign(
            [
                'VERSION' => \Piwigo\Config\Config::showVersion() ? AppInfo::VERSION : '',
                'PHPWG_URL' => defined('PHPWG_URL') ? str_replace('http:', 'https:', PHPWG_URL) : '',
                // web-vitals RUM beacon (docs/PLAN-REPLAY.md P1, item 11b) --
                // fixed, non-hashed filename (vite.config.ts), so no
                // manifest.json lookup is needed to reference it.
                'VITALS_SCRIPT_URL' => $this->urlService->getRootUrl() . 'dist/vitals.js',
            ]
        );

        // --------------------------------------------------------------------- contact

        if (! \Piwigo\Auth\AccessControl::isAGuest()) {
            $template->assign(
                'CONTACT_MAIL',
                new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())->getWebmasterMailAddress()
            );
        }

        $this->telemetrySender->send();

        // ------------------------------------------------------------- generation time
        $debug_vars = [];

        if (\Piwigo\Config\Config::showQueries()) {
            $debug_vars = array_merge($debug_vars, [
                'QUERIES_LIST' => \Piwigo\Core\PageState::current()->debugOutput,
            ]);
        }

        if (\Piwigo\Config\Config::showGt()) {
            $count_queries = \Piwigo\Core\PageState::current()->countQueries;
            $queries_time = \Piwigo\Core\PageState::current()->queriesTime;

            $time = \Piwigo\Core\TimingHelper::getElapsedTime($startTime, \Piwigo\Core\TimingHelper::getMoment());

            $debug_vars = array_merge(
                $debug_vars,
                [
                    'TIME' => $time,
                    'NB_QUERIES' => $count_queries,
                    'SQL_TIME' => number_format($queries_time, 3, '.', ' ') . ' s',
                ]
            );
        }

        $template->assign('debug', $debug_vars);

        // ------------------------------------------------------------- mobile version
        if (! self::emptyValue(\Piwigo\Config\Config::mobilTheme()) && (\Piwigo\Core\DeviceHelper::getDevice() !== 'desktop' || \Piwigo\Core\DeviceHelper::mobileTheme())) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $template->assign(
                'TOGGLE_MOBILE_THEME_URL',
                $this->urlService->addUrlParams(
                    htmlspecialchars(is_string($request_uri) ? $request_uri : ''),
                    [
                        'mobile' => \Piwigo\Core\DeviceHelper::mobileTheme() ? 'false' : 'true',
                    ]
                )
            );
        }

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_page_tail');
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
