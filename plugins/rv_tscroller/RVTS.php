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
        functions_plugins::add_event_handler('loc_begin_index', self::on_index_begin(...), EVENT_HANDLER_PRIORITY_NEUTRAL + 10);
    }

    public static function on_index_begin(): void
    {
        global $page;
        $is_ajax      = isset($_GET['rvts']);
        $is_ajax_cats = isset($_GET['rvts_cats']);

        if ($is_ajax_cats) {
            // $page['startcat'] is already parsed from the URL path (e.g. /startcat-12)
            // by functions_url.php before plugins are invoked — just clamp it.
            $page['startcat']  = max(0, (int) ($page['startcat'] ?? 0));
            $page['root_path'] = functions_url::get_absolute_root_url(false);
            global $user, $template, $conf;
            require PHPWG_ROOT_PATH . 'inc/category_cats.php';
            $html = $template->get_template_vars('CATEGORIES') ?? '';
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
            exit;
        }

        if (! $is_ajax) {
            // Album scroll is wired up on every index page; on_end_index_cats
            // will silently return when there is nothing more to load.
            functions_plugins::add_event_handler('loc_end_index', self::on_end_index_cats(...));

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

            functions_plugins::add_event_handler('loc_end_index_thumbnails', self::on_index_thumbnails_ajax(...), EVENT_HANDLER_PRIORITY_NEUTRAL + 5);
            $page['root_path'] = functions_url::get_absolute_root_url(false);
            $page['body_id'] = 'scroll';
            global $user, $template, $conf;
            require PHPWG_ROOT_PATH . 'inc/category_default.php';
        }
    }

    /**
     * Injects the RVTS_CATS JS config when there are more album pages to load.
     * Replaces the traditional cats_navbar with infinite scroll.
     */
    public static function on_end_index_cats(): void
    {
        global $page, $template, $conf, $user;

        $total    = $page['total_categories'] ?? 0;
        $startcat = $page['startcat'] ?? 0;
        $per_page = $user['nb_image_page'];

        if ($total <= $per_page + $startcat) {
            return; // All albums visible — nothing to scroll.
        }

        // Build URL model using the same path-style /startcat-N format that
        // create_navigation_bar uses (clean_url=true). duplicate_index_url ignores
        // the startcat param, so we append it manually to the base URL.
        $base_url       = functions_url::duplicate_index_url([], ['startcat']);
        $url_model      = rtrim($base_url, '/') . '/startcat-%startcat%';
        $ajax_url_model = functions_url::add_url_params($url_model, ['rvts_cats' => '1']);
        $url_model      = str_replace('&amp;', '&', $url_model);
        $ajax_url_model = str_replace('&amp;', '&', $ajax_url_model);

        $my_base_name      = basename(__DIR__);
        $ajax_loader_image = functions_url::get_root_url() . "plugins/{$my_base_name}/ajax-loader.gif";

        $next                = $startcat + $per_page;
        $ajax_url_0          = ord($ajax_url_model[0]);
        $ajax_url_rest       = addcslashes(substr($ajax_url_model, 1), "'\\</");
        $ajax_loader_img_js  = json_encode($ajax_loader_image);
        $js_path             = functions_url::get_root_url() . "plugins/{$my_base_name}/rv_tscroller.js";

        $template->block_footer_script(
            null,
            <<<JS
                <script>
                var RVTS_CATS = {
                    ajaxUrlModel: String.fromCharCode({$ajax_url_0})+'{$ajax_url_rest}',
                    start: {$startcat},
                    perPage: {$per_page},
                    next: {$next},
                    total: {$total},
                    ajaxLoaderImage: {$ajax_loader_img_js}
                };
                </script>
                <script type="module">
                import { initRVTS_CATS } from '{$js_path}';
                if (window.RVTS_CATS) initRVTS_CATS(window.RVTS_CATS);
                </script>
                JS
        );

        // Suppress the traditional album nav bar — scroll replaces it.
        $template->assign('cats_navbar', []);
    }

    public static function on_index_thumbnails(
        array $thumbs
    ): array {
        global $page, $template;
        $total = $page['total_items'] ?? count($page['items']);

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
        $start = (int) $page['start'];
        $per_page = $page['nb_image_page'];
        $moreMsg = 'See the remaining %d photos';

        if ($GLOBALS['lang_info']['code'] != 'en') {
            functions::load_language('lang', __DIR__ . '/');
            $moreMsg = functions::l10n($moreMsg);
        }

        // String.fromCharCode on the first char prevents Google bot from crawling these URLs
        $ajax_url_model_0 = ord($ajax_url_model[0]);
        $ajax_url_model_rest = addcslashes(substr($ajax_url_model, 1), "'\\</");
        $url_model_0 = ord($url_model[0]);
        $url_model_rest = addcslashes(substr($url_model, 1), "'\\</");
        $next = $start + $per_page;
        $prevMsg = functions::l10n('Previous');

        $moreMsg_js = json_encode($moreMsg);
        $prevMsg_js = json_encode($prevMsg);
        $ajax_loader_image_js = json_encode($ajax_loader_image);
        $js_path = functions_url::get_root_url() . "plugins/{$my_base_name}/rv_tscroller.js";

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
                    moreMsg: {$moreMsg_js},
                    prevMsg: {$prevMsg_js},
                    ajaxLoaderImage: {$ajax_loader_image_js}
                };
                document.querySelector('.navigationBar') && (document.querySelector('.navigationBar').style.display = 'none');
                </script>
                <script type="module">
                import { initRVTS } from '{$js_path}';
                if (window.RVTS) initRVTS(window.RVTS);
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
        // If there are no items or all items are loaded, RVTS is not defined
        // so there's nothing for rv_tscroller.js to do. No need to load it.
    }
}
