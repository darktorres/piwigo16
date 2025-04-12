<?php

declare(strict_types=1);

namespace Piwigo\plugins\AdminTools\inc;

use Piwigo\admin\inc\themes;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\FileCombiner;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('ADMINTOOLS_PATH')) {
    exit('Hacking attempt!');
}

/**
 * Class managing multi views system
 */
class MultiView
{
    private bool $is_admin = false;

    private array $data = [];

    private array $data_url_params = [];

    private array $user = [];

    /**
     * Constructor, load $data from session
     */
    public function __construct()
    {
        global $conf;

        $this->data = array_merge(
            [
                'view_as' => 0,
                'theme' => '',
                'lang' => '',
                'show_queries' => $conf->show_queries,
                'debug_l10n' => $conf->debug_l10n,
                'debug_template' => $conf->debug_template,
                'template_combine_files' => $conf->template_combine_files,
                'no_history' => false,
            ],
            functions_session::pwg_get_session_var('multiview', [])
        );

        $this->data_url_params = array_keys($this->data);
        $this->data_url_params = array_map(fn (string $d): string => 'ato_' . $d, $this->data_url_params);
    }

    public function is_admin(): bool
    {
        return $this->is_admin;
    }

    public function get_data(): array
    {
        return $this->data;
    }

    public function get_user(): array
    {
        return $this->user;
    }

    /**
     * Returns the current url minus MultiView params
     *
     * @param bool $with_amp - adds ? or & at the end of the url
     */
    public function get_clean_url(
        bool $with_amp = false
    ): string {
        if (functions::script_basename() === 'picture') {
            $url = functions_url::duplicate_picture_url([], $this->data_url_params);
        } elseif (functions::script_basename() === 'index') {
            $url = functions_url::duplicate_index_url([], $this->data_url_params);
        } else {
            $url = functions_url::get_query_string_diff($this->data_url_params);
        }

        if ($with_amp) {
            $url .= str_contains($url, '?') ? '&' : '?';
        }

        return $url;
    }

    /**
     * Returns the current url minus MultiView params
     *
     * @param bool $with_amp - adds ? or & at the end of the url
     */
    public function get_clean_admin_url(
        bool $with_amp = false
    ): string {
        $url = './admin.php';

        $get = $_GET;
        unset($get['page'], $get['section'], $get['tag']);

        if (count($get) == 0 &&
            ! empty($_SERVER['QUERY_STRING'])
        ) {
            $url .= '?' . str_replace('&', '&amp;', $_SERVER['QUERY_STRING']);
        }

        if ($with_amp) {
            $url .= str_contains($url, '?') ? '&' : '?';
        }

        return $url;
    }

    /**
     * Triggered on "user_init", change current view depending of URL params.
     */
    public function user_init(): void
    {
        global $user, $conf;

        $this->is_admin = functions_user::is_admin();

        $this->user = [
            'id' => $user['id'],
            'username' => $user['username'],
            'language' => $user['language'],
            'theme' => $user['theme'],
        ];

        // inactive on ws.php to allow AJAX admin tasks
        if ($this->is_admin &&
            functions::script_basename() !== 'ws'
        ) {
            // show_queries
            if (isset($_GET['ato_show_queries'])) {
                $this->data['show_queries'] = (bool) $_GET['ato_show_queries'];
            }

            $conf->show_queries = $this->data['show_queries'];

            if ($this->data['view_as'] == 0) {
                $this->data['view_as'] = $user['id'];
            }

            if (empty($this->data['lang'])) {
                $this->data['lang'] = $user['language'];
            }

            if (empty($this->data['theme'])) {
                $this->data['theme'] = $user['theme'];
            }

            // view_as
            if (! defined('IN_ADMIN')) {
                if (isset($_GET['ato_view_as'])) {
                    $this->data['view_as'] = (int) $_GET['ato_view_as'];
                }

                if ($this->data['view_as'] != $user['id']) {
                    $user = functions_user::build_user($this->data['view_as'], true);

                    if (isset($_GET['ato_view_as'])) {
                        $this->data['theme'] = $user['theme'];
                        $this->data['lang'] = $user['language'];
                    }
                }
            }

            // theme
            if (isset($_GET['ato_theme'])) {
                $this->data['theme'] = $_GET['ato_theme'];
            }

            $user['theme'] = $this->data['theme'];

            // lang
            if (isset($_GET['ato_lang'])) {
                functions::check_input_parameter('ato_lang', $_GET, false, '/^[a-z]{2,3}_[A-Z]{2}$/');
                $this->data['lang'] = $_GET['ato_lang'];
            }

            $user['language'] = $this->data['lang'];

            // debug_l10n
            if (isset($_GET['ato_debug_l10n'])) {
                $this->data['debug_l10n'] = (bool) $_GET['ato_debug_l10n'];
            }

            $conf->debug_l10n = $this->data['debug_l10n'];

            // debug_template
            if (isset($_GET['ato_debug_template'])) {
                $this->data['debug_template'] = (bool) $_GET['ato_debug_template'];
            }

            $conf->debug_template = $this->data['debug_template'];

            // template_combine_files
            if (isset($_GET['ato_template_combine_files'])) {
                $this->data['template_combine_files'] = (bool) $_GET['ato_template_combine_files'];
            }

            $conf->template_combine_files = $this->data['template_combine_files'];

            // no_history
            if (isset($_GET['ato_no_history'])) {
                $this->data['no_history'] = (bool) $_GET['ato_no_history'];
            }

            if ($this->data['no_history']) {
                $ret_false = (fn (): bool => false);
                functions_plugins::add_event_handler('pwg_log_allowed', $ret_false);
                functions_plugins::add_event_handler('pwg_log_update_last_visit', $ret_false);
            }

            $this->save();
        }
    }

    /**
     * Returns the language of the current user if different from the current language
     * false otherwise
     */
    public function get_user_language(): string|bool
    {
        if (isset($this->user['language']) &&
            isset($this->data['lang']) &&
            $this->user['language'] != $this->data['lang']
        ) {
            return $this->user['language'];
        }

        return false;
    }

    /**
     * Triggered on "init", in order to clean template files (not initialized on "user_init")
     */
    public function init(): void
    {
        if ($this->is_admin && isset($_GET['ato_purge_template'])) {
            global $template;
            $template->delete_compiled_templates();
            FileCombiner::clear_combined_files();
        }
    }

    /**
     * Mark browser session cache for deletion
     */
    public static function invalidate_cache(): void
    {
        global $conf;
        functions::conf_update_param('multiview_invalidate_cache', true, true);
    }

    /**
     * Register custom API methods
     */
    public static function register_ws(
        array $arr
    ): void {
        $service = &$arr[0];

        $service->addMethod(
            'multiView.getData',
            self::ws_get_data(...),
            [],
            'AdminTools private method.',
            null,
            [
                'admin_only' => true,
                'hidden' => true,
            ]
        );
    }

    /**
     * API method
     * Return full list of users, themes and languages
     */
    public static function ws_get_data(
        array $params
    ): array {
        global $conf;

        // get users
        $query = <<<SQL
            SELECT {$conf->user_fields['id']} AS id, {$conf->user_fields['username']} AS username, status
            FROM users AS u
            INNER JOIN user_infos AS i ON {$conf->user_fields['id']} = user_id
            ORDER BY CONVERT({$conf->user_fields['username']}, CHAR);
            SQL;
        $out['users'] = functions_mysqli::query2array($query);

        // get themes
        $themes = new themes();

        foreach (array_keys($themes->db_themes_by_id) as $theme) {
            if (! empty($theme)) {
                $out['themes'][] = $theme;
            }
        }

        // get languages
        foreach (functions::get_languages() as $code => $name) {
            $out['languages'][] = [
                'id' => $code,
                'name' => $name,
            ];
        }

        functions::conf_delete_param('multiview_invalidate_cache');

        return $out;
    }

    /**
     * Save $data in session
     */
    private function save(): void
    {
        functions_session::pwg_set_session_var('multiview', $this->data);
    }
}
