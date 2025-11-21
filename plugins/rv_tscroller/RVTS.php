<?php

declare(strict_types=1);

/*
Plugin Name: RV Thumb Scroller
Version: 12.a
Plugin URI: http://piwigo.org/ext/extension_view.php?eid=493
Description: Infinite scroll - loads thumbnails on index page as you scroll down the page
Author: rvelices
Author URI: http://www.modusoptimus.com
Has Settings: false
*/

namespace Piwigo\plugins\rv_tscroller;

use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;

final class RVTS
{
    public static function on_end_section_init(): void
    {
        // global $page;
        // $page['nb_image_page'] *= functions_session::pwg_get_session_var('rvts_mult', 1);

        // if (count($page['items']) < $page['nb_image_page'] + 3 && (! $page['start'] || functions::script_basename() === 'picture')) {
        //     $page['nb_image_page'] = max($page['nb_image_page'], count($page['items']));
        // }

        functions_plugins::add_event_handler('loc_begin_index', self::on_index_begin(...), EVENT_HANDLER_PRIORITY_NEUTRAL + 10);
        // Listen for GDThumb config changes to clear cached height
        functions_plugins::add_event_handler('gdthumb_config_changed', self::on_gdthumb_config_changed(...));
    }

    public static function on_gdthumb_config_changed(array $params): void
    {
        // Clear any cached state that depends on thumbnail height
        functions_session::pwg_delete_session_var('rvts_cache_height');
    }

    public static function on_index_begin(): void
    {
        global $page;
        $is_ajax = isset($_GET['rvts']);

        // Always set body_id when rv_tscroller is active (both initial and AJAX loads)
        $page['body_id'] = 'scroll';

        if (! $is_ajax) {
            if (empty($page['items'])) {
                functions_plugins::add_event_handler('loc_end_index', self::on_end_index(...));
            } else {
                functions_plugins::add_event_handler('loc_end_index_thumbnails', self::on_index_thumbnails(...));
            }
        } else {
            $adj = (int) ($_GET['adj'] ?? null);

            if ($adj !== 0) {
                $mult = functions_session::pwg_get_session_var('rvts_mult', 1);

                if ($adj > 0 &&
                    $mult < 5
                ) {
                    functions_session::pwg_set_session_var('rvts_mult', ++$mult);
                }

                if ($adj < 0 &&
                    $mult > 1
                ) {
                    functions_session::pwg_set_session_var('rvts_mult', --$mult);
                }
            }

            // Apply multiplier to pagination
            $mult = functions_session::pwg_get_session_var('rvts_mult', 1);
            if ($mult > 1) {
                $page['nb_image_page'] = (int) ($page['nb_image_page'] * $mult);

                // Prevent unbounded growth
                $max_per_page = 500;
                if ($page['nb_image_page'] > $max_per_page) {
                    $page['nb_image_page'] = $max_per_page;
                }
            }

            // $page['nb_image_page'] = (int) $_GET['rvts'];
            functions_plugins::add_event_handler('loc_end_index_thumbnails', self::on_index_thumbnails_ajax(...), EVENT_HANDLER_PRIORITY_NEUTRAL + 5);
            $page['root_path'] = functions_url::get_absolute_root_url(false);
            global $user, $template, $conf;
            require __DIR__ . '/../../inc/category_default.php';
        }
    }

    public static function on_index_thumbnails(
        array $thumbs
    ): array {
        global $page, $template;
        $total = count($page['items']);

        if (count($thumbs) >= $total) {
            functions_plugins::add_event_handler('loc_end_index', self::on_end_index(...));
            return $thumbs;
        }

        $url_model = str_replace('123456789', '%start%', functions_url::duplicate_index_url([
            'start' => 123456789,
        ]));
        $ajax_url_model = functions_url::add_url_params($url_model, [
            'rvts' => '%per%',
        ]);

        $url_model = str_replace('&amp;', '&', $url_model);
        $ajax_url_model = str_replace('&amp;', '&', $ajax_url_model);

        $my_base_name = basename(__DIR__);
        $ajax_loader_image = functions_url::get_root_url() . "plugins/{$my_base_name}/ajax-loader.gif";
        $template->func_combine_script([
            'id' => $my_base_name,
            'load' => 'async',
            'path' => 'plugins/' . $my_base_name . '/rv_tscroller.js',
            'version' => RVTS_VERSION,
        ]);
        $start = (int) $page['start'];
        $per_page = $page['nb_image_page'];
        $moreMsg = 'See the remaining %d photos';

        if ($GLOBALS['lang_info']['code'] != 'en') {
            functions::load_language('lang', __DIR__ . '/');
            $moreMsg = functions::l10n($moreMsg);
        }

        // the String.fromCharCode comes from google bot which somehow manage to get these urls
        $ajax_url_model_0 = ord($ajax_url_model[0]);
        $ajax_url_model_rest = substr($ajax_url_model, 1);
        $url_model_0 = ord($url_model[0]);
        $url_model_rest = substr($url_model, 1);
        $next = $start + $per_page;
        $prevMsg = functions::l10n('Previous');

        $template->block_footer_script(
            null,
            <<<JS
                <script>
                var RVTS = {
                    ajaxUrlModel: String.fromCharCode({$ajax_url_model_0})+'{$ajax_url_model_rest}',
                    start: {$start},
                    perPage: {$per_page},
                    next: {$next},
                    total: {$total},
                    urlModel: String.fromCharCode({$url_model_0})+'{$url_model_rest}',
                    moreMsg: '{$moreMsg}',
                    prevMsg: '{$prevMsg}',
                    ajaxLoaderImage: '{$ajax_loader_image}'
                };
                var navBar = document.querySelector('.navigationBar');
                if (navBar) navBar.style.display = 'none';
                </script>
                JS
        );

        return $thumbs;
    }

    public static function on_index_thumbnails_ajax(
        array $thumbs
    ): never {
        global $template;
        $template->assign('thumbnails', $thumbs);
        header('Content-Type: text/html; charset=utf-8');
        $template->pparse('index_thumbnails');
        exit;
    }

    public static function on_end_index(): void
    {
        global $template;
        $req = null;

        foreach ($template->scriptLoader->get_all() as $script) {
            if ($script->load_mode == 2 &&
                ! $script->is_remote() &&
                count($script->precedents) == 0
            ) {
                $req = $script->id;
            }
        }

        if ($req != null) {
            $my_base_name = basename(__DIR__);
            $template->func_combine_script([
                'id' => $my_base_name,
                'load' => 'async',
                'path' => 'plugins/' . $my_base_name . '/rv_tscroller.js',
                'require' => $req,
                'version' => RVTS_VERSION,
            ]);
        }

        //var_export($template->scriptLoader);
    }
}
