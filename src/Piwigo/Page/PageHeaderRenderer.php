<?php

declare(strict_types=1);

namespace Piwigo\Page;

/**
 * Renders the page `<head>`/opening chrome into $template. Injects
 * nothing -- same "no constructor deps" shape as Html\HtmlService/
 * Menu\MenubarRenderer: cross-domain calls (get_pwg_charset(),
 * get_gallery_home_url(), trigger_notify()) stay as plain
 * global-function calls to modules migrated in earlier phases.
 */
final class PageHeaderRenderer
{
    /**
     * @param string $title set by the including page script, right before
     *   the original page_header.php include.
     * @param string|null $refresh optional meta-refresh delay in seconds;
     *   only applied together with $urlLink, matching the original
     *   $refresh/$url_link top-level-scope contract (no current caller
     *   sets either).
     */
    public function render(string $title, ?string $refresh = null, ?string $urlLink = null): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var list<string>|null $header_notes
         */
        global $conf, $page, $header_notes;
        $template = \Piwigo\Template\CurrentTemplate::get();

        $template->set_filenames([
            'header' => 'header.tpl',
        ]);

        trigger_notify('loc_begin_page_header');

        $show_mobile_app_banner = \Piwigo\Config\ConfigDb::confGetParam('show_mobile_app_banner_in_gallery', false);
        if (defined('IN_ADMIN') and IN_ADMIN) {
            $show_mobile_app_banner = \Piwigo\Config\ConfigDb::confGetParam('show_mobile_app_banner_in_admin', true);
        }

        // Config values loaded from the piwigo_config DB table (TEXT column) are
        // always strings; $page['page_banner'] is only ever assigned string literals
        // (see popuphelp.php, admin.php, admin/popuphelp.php).
        /** @var string $conf_gallery_title */
        $conf_gallery_title = $conf['gallery_title'];
        /** @var string $page_banner */
        $page_banner = $page['page_banner'] ?? $conf['page_banner'];

        $template->assign(
            [
                'GALLERY_TITLE' => $page['gallery_title'] ?? $conf_gallery_title,

                'PAGE_BANNER' => trigger_change(
                    'render_page_banner',
                    str_replace(
                        '%gallery_title%',
                        $conf_gallery_title,
                        $page_banner
                    )
                ),

                'BODY_ID' => $page['body_id'] ?? '',

                'CONTENT_ENCODING' => \Piwigo\Core\CharsetHelper::getPwgCharset(),
                'PAGE_TITLE' => strip_tags($title),

                'U_HOME' => get_gallery_home_url(),

                'LEVEL_SEPARATOR' => $conf['level_separator'],

                'SHOW_MOBILE_APP_BANNER' => $show_mobile_app_banner,

                'BODY_CLASSES' => $page['body_classes'],

                'BODY_DATA' => json_encode($page['body_data']),
            ]
        );

        // Header notes
        if (! self::emptyValue($header_notes)) {
            $template->assign('header_notes', $header_notes);
        }

        // No referencing is required
        // (real invariant: $page['meta_robots'], when set, is always a name=>1 map --
        // see notification.php, popuphelp.php, picture.php, index.php,
        // admin/popuphelp.php, include/section_init.inc.php)
        if (! isset($page['meta_robots']) || ! is_array($page['meta_robots'])) {
            $page['meta_robots'] = [];
        }
        if (! (bool) $conf['meta_ref']) {
            $page['meta_robots']['noindex'] = 1;
            $page['meta_robots']['nofollow'] = 1;
        }

        if (! self::emptyValue($page['meta_robots'])) {
            $template->append(
                'head_elements',
                '<meta name="robots" content="'
                  . implode(',', array_keys($page['meta_robots']))
                  . '">'
            );
        }
        if (! isset($page['meta_robots']['noindex'])) {
            $template->assign('meta_ref', 1);
        }

        // refresh
        $refresh_numeric = $refresh !== null && is_numeric($refresh) ? $refresh : null;
        if ($refresh_numeric !== null and intval($refresh_numeric) >= 0
            and $urlLink !== null) {
            $template->assign(
                [
                    'page_refresh' => [
                        'TIME' => $refresh_numeric,
                        'U_REFRESH' => $urlLink,
                    ],
                ]
            );
        }

        trigger_notify('loc_end_page_header');

        header('Content-Type: text/html; charset=' . \Piwigo\Core\CharsetHelper::getPwgCharset());
        $template->parse('header');

        trigger_notify('loc_after_page_header');
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
