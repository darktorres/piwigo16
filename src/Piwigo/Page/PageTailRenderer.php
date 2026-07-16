<?php

declare(strict_types=1);

namespace Piwigo\Page;

use Piwigo\Core\AppInfo;
use Piwigo\Template\Template;

/**
 * Renders the page footer into $template. Injects nothing -- same shape
 * as PageHeaderRenderer.
 *
 * The original page_tail.php's "check for Piwigo updates" block
 * (constructing Piwigo\Admin\updates) is deliberately NOT ported here --
 * L3Presentation may not depend on L4Integration (Admin), confirmed via a
 * real deptrac violation when tried. It stays inline in the thin
 * page_tail.php delegate, same "keep layer-crossing glue outside the
 * class" shape as admin/menubar.php's own $_POST handling.
 */
final class PageTailRenderer
{
    public function render(float $startTime): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var string $debug
         * @var Template $template
         * @var array<string, mixed> $page
         */
        global $conf, $debug, $template, $page;

        $template->set_filenames([
            'tail' => 'footer.tpl',
        ]);

        trigger_notify('loc_begin_page_tail');

        $template->assign(
            [
                'VERSION' => (bool) $conf['show_version'] ? AppInfo::VERSION : '',
                'PHPWG_URL' => defined('PHPWG_URL') ? str_replace('http:', 'https:', PHPWG_URL) : '',
                // web-vitals RUM beacon (docs/PLAN-REPLAY.md P1, item 11b) --
                // fixed, non-hashed filename (vite.config.ts), so no
                // manifest.json lookup is needed to reference it.
                'VITALS_SCRIPT_URL' => get_root_url() . 'dist/vitals.js',
            ]
        );

        // --------------------------------------------------------------------- contact

        if (! \Piwigo\Auth\AccessControl::isAGuest()) {
            $template->assign(
                'CONTACT_MAIL',
                get_webmaster_mail_address()
            );
        }

        send_piwigo_infos();

        // ------------------------------------------------------------- generation time
        $debug_vars = [];

        if ((bool) $conf['show_queries']) {
            $debug_vars = array_merge($debug_vars, [
                'QUERIES_LIST' => $debug,
            ]);
        }

        if ((bool) $conf['show_gt']) {
            $count_queries = $page['count_queries'] ?? null;
            if (! is_int($count_queries)) {
                $count_queries = 0;
                $page['count_queries'] = 0;
                $page['queries_time'] = 0;
            }

            $queries_time = $page['queries_time'] ?? 0;
            $queries_time = is_numeric($queries_time) ? (float) $queries_time : 0.0;

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
        if (! self::emptyValue($conf['mobile_theme']) && (get_device() !== 'desktop' || mobile_theme())) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $template->assign(
                'TOGGLE_MOBILE_THEME_URL',
                add_url_params(
                    htmlspecialchars(is_string($request_uri) ? $request_uri : ''),
                    [
                        'mobile' => mobile_theme() ? 'false' : 'true',
                    ]
                )
            );
        }

        trigger_notify('loc_end_page_tail');
        //
        // Generate the page
        //
        $template->parse('tail');
        $template->p();
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
