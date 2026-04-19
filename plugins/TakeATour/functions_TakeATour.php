<?php

declare(strict_types=1);

namespace Piwigo\plugins\TakeATour;

use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

final class functions_TakeATour
{
    public static function TAT_tour_setup(): void
    {
        if (! functions_user::is_admin()) {
            return;
        }

        global $template, $TAT_restart, $conf;
        $tour_to_launch = functions_session::pwg_get_session_var('tour_to_launch');
        functions::load_language('plugin.lang', PHPWG_PLUGINS_PATH . 'TakeATour/', [
            'force_fallback' => 'en_UK',
        ]);

        [, $tour_name] = explode('/', $tour_to_launch);
        functions::load_language('tour_' . $tour_name . '.lang', PHPWG_PLUGINS_PATH . 'TakeATour/', [
            'force_fallback' => 'en_UK',
        ]);

        if (in_array($tour_name, ['edit_photos', 'manage_albums', 'config', 'plugins'], true)) {
            // because these tours come from splitting the original "first_contact"
            // tour, we also load this language file
            functions::load_language('tour_first_contact.lang', PHPWG_PLUGINS_PATH . 'TakeATour/', [
                'force_fallback' => 'en_UK',
            ]);
        }

        $template->set_filename('TAT_js_css', PHPWG_PLUGINS_PATH . 'TakeATour/tpl/js_css.tpl');
        $template->assign('ADMIN_THEME', $conf->admin_theme);
        require_once PHPWG_ROOT_PATH . 'inc/vite_helper.php';
        \Piwigo\Vite\vite_assign_modules($template, ['tat_tour' => 'plugins/TakeATour/js/Tour']);
        $template->parse('TAT_js_css');

        if (isset($TAT_restart) &&
            $TAT_restart
        ) {
            $TAT_restart = false;
            $template->assign('TAT_restart', true);
        }

        $tat_path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME']);
        $template->assign('TAT_path', $tat_path);
        $template->assign('ABS_U_ADMIN', functions_url::get_absolute_root_url()); // absolute one due to public pages and $conf->question_mark_in_urls = false + $conf->php_extension_in_urls = false;

        // some tours may need admin functions (like 2_8_0 needs get_orphans)

        require $tour_to_launch . '/config.php';
        $template->set_filename('TAT_tour_tpl', $TOUR_PATH);

        functions_plugins::trigger_notify('TAT_before_parse_tour');

        $template->parse('TAT_tour_tpl');
    }

    public static function TAT_help(): void
    {
        global $template;
        functions::load_language('plugin.lang', PHPWG_PLUGINS_PATH . 'TakeATour/');
        $template->set_prefilter('help', self::TAT_help_prefilter(...));
    }

    public static function TAT_help_prefilter(
        array|string $content
    ): array|string {

        $search = '<div id="helpContent">';
        $replacement = <<<HTML
            <div id="helpContent">
              <fieldset>
                <legend>{'Visit your Piwigo!'|translate}</legend>
                <p class="nextStepLink">
                  <a href="admin.php?page=plugin-TakeATour">
                    {'Take a tour and discover the features of your Piwigo gallery » Go to the available tours'|translate}
                  </a>
                </p>
              </fieldset>
            HTML;
        return str_replace($search, $replacement, $content);

    }

    public static function TAT_no_photo_yet(): void
    {
        global $template;
        functions::load_language('plugin.lang', PHPWG_PLUGINS_PATH . 'TakeATour/');
        $template->set_prefilter('no_photo_yet', self::TAT_no_photo_yet_prefilter(...));
        $template->assign(
            [
                'F_ACTION' => functions_url::get_root_url() . 'admin.php',
                'pwg_token' => functions::get_pwg_token(),
            ]
        );
    }

    public static function TAT_no_photo_yet_prefilter(
        array|string $content
    ): array|string {
        $search = '<div class="bigButton"><a href="{$next_step_url}">{\'I want to add photos\'|translate}</a></div>';
        $replacement = '<div class="bigButton"><a href="{$F_ACTION}?submitted_tour_path=tours/first_contact&pwg_token={$pwg_token}">{\'Start the Tour\'|translate}</a></div>';
        return str_replace($search, $replacement, $content);
    }
}
