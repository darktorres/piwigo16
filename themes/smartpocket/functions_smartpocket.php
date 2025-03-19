<?php

declare(strict_types=1);

namespace Piwigo\themes\smartpocket;

use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\menubar;

class functions_smartpocket
{
    public static function sp_select_all_thumbnails(
        array $selection
    ): array|bool|int|string {
        global $page, $template;

        $template->assign('page_selection', array_flip($selection));
        $template->assign('thumb_picker', new SPThumbPicker());
        return $page['items'];
    }

    public static function sp_select_all_categories(
        array $selection
    ): array {
        global $tpl_thumbnails_var;
        return $tpl_thumbnails_var;
    }

    public static function sp_end_section_init(): void
    {
        global $page, $template;

        // variables to log history
        $template->assign(
            'smartpocket_log_history',
            [
                'cat_id' => ($page['category']['id'] ?? null),
                'section' => $page['section'],
                'tags_string' => (isset($page['tag_ids']) ? implode(',', $page['tag_ids']) : ''),
            ]
        );
    }

    public static function mobile_link(): void
    {
        global $template, $conf;

        $config = functions::safe_unserialize($conf['smartpocket']);
        $template->assign('smartpocket', $config);

        if (! empty($conf['mobile_theme']) &&
           (functions::get_device() != 'desktop' || functions::mobile_theme())
        ) {
            $template->assign([
                'TOGGLE_MOBILE_THEME_URL' => functions_url::add_url_params(htmlspecialchars($_SERVER['REQUEST_URI']), [
                    'mobile' => functions::mobile_theme() ? 'false' : 'true',
                ]),
            ]);
        }
    }

    public static function add_menu_on_public_pages(): ?bool
    {
        if (function_exists('initialize_menu')) {
            return false;
            # The current page has already the menu
        }

        global $template, $page, $conf;

        if (isset($page['body_id']) and
            $page['body_id'] == 'thePicturePage'
        ) {
            $template->set_filenames([
                'add_menu_on_public_pages' => dirname(__FILE__) . '/template/add_menu_on_public_pages.tpl',
            ]);
            menubar::initialize_menu();
            $template->parse('add_menu_on_public_pages');
        }

        return null;
    }
}
