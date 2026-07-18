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
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var array<string, mixed>
         */
        global $page;
        /**
         * @var list<string>|null
         */
        global $header_notes;
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
        $conf_gallery_title = \Piwigo\Config\Config::galleryTitle();
        /** @var string $page_banner */
        $page_banner = $page['page_banner'] ?? \Piwigo\Config\Config::pageBanner();

        $template->assign(
            [
                'GALLERY_TITLE' => $conf_gallery_title,

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

                'LEVEL_SEPARATOR' => \Piwigo\Config\Config::levelSeparator(),

                'SHOW_MOBILE_APP_BANNER' => $show_mobile_app_banner,

                'BODY_CLASSES' => \Piwigo\Core\PageState::current()->bodyClasses,

                'BODY_DATA' => json_encode(\Piwigo\Core\PageState::current()->bodyData),
            ]
        );

        // Header notes
        if (! self::emptyValue($header_notes)) {
            $template->assign('header_notes', $header_notes);
        }

        $pageState = \Piwigo\Core\PageState::current();
        if (! \Piwigo\Config\Config::metaRef()) {
            $pageState->setMetaRobotsFlag('noindex');
            $pageState->setMetaRobotsFlag('nofollow');
        }

        if (! self::emptyValue($pageState->metaRobots)) {
            $template->append(
                'head_elements',
                '<meta name="robots" content="'
                  . implode(',', array_keys($pageState->metaRobots))
                  . '">'
            );
        }
        if (! isset($pageState->metaRobots['noindex'])) {
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
