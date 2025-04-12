<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\inc;

use Piwigo\inc\dblayer\functions_mysqli;
use SmartyException;

final class functions_html
{
    /**
     * Generates breadcrumb from categories list.
     * Categories string returned contains categories as given in the input
     * array $cat_information. $cat_information array must be an array
     * of array( id=>?, name=>?, permalink=>?). If url input parameter is null,
     * returns only the categories name without links.
     */
    public static function get_cat_display_name(
        array $cat_information,
        ?string $url = ''
    ): string {
        global $conf;

        //$output = '<a href="'.\Piwigo\inc\functions_url::get_absolute_root_url().$conf->home_page.'">'.\Piwigo\inc\functions::l10n('Home').'</a>';
        $output = '';
        $is_first = true;

        foreach ($cat_information as $cat) {
            if (! is_array($cat)) {
                trigger_error('get_cat_display_name wrong type for category ', E_USER_WARNING);
            }

            $cat['name'] = functions_plugins::trigger_change(
                'render_category_name',
                $cat['name'],
                'get_cat_display_name'
            );

            if ($is_first) {
                $is_first = false;
            } else {
                $output .= $conf->level_separator;
            }

            if (! isset($url)) {
                $output .= $cat['name'];
            } elseif ($url === '') {
                $output .= '<a href="'
                      . functions_url::make_index_url(
                          [
                              'category' => $cat,
                          ]
                      )
                      . '">';
                $output .= $cat['name'] . '</a>';
            } else {
                $output .= '<a href="./' . $url . $cat['id'] . '">';
                $output .= $cat['name'] . '</a>';
            }
        }

        return $output;
    }

    /**
     * Generates breadcrumb from categories list using a cache.
     * @see get_cat_display_name()
     */
    public static function get_cat_display_name_cache(
        string $uppercats,
        ?string $url = '',
        bool $single_link = false,
        ?string $link_class = null,
        ?string $auth_key = null
    ): string {
        global $cache, $conf;

        $add_url_params = [];

        if (isset($auth_key)) {
            $add_url_params['auth'] = $auth_key;
        }

        if (! isset($cache['cat_names'])) {
            $query = <<<SQL
                SELECT id, name, permalink
                FROM categories;
                SQL;
            $cache['cat_names'] = functions_mysqli::query2array($query, 'id');
        }

        $output = '';

        if ($single_link) {
            $uppercats_array = explode(',', $uppercats);
            $single_url = functions_url::add_url_params(functions_url::get_root_url() . $url . array_pop($uppercats_array), $add_url_params);
            $output .= '<a href="' . $single_url . '"';

            if (isset($link_class)) {
                $output .= ' class="' . $link_class . '"';
            }

            $output .= '>';
        }

        $is_first = true;

        foreach (explode(',', $uppercats) as $category_id) {
            $cat = $cache['cat_names'][$category_id];

            $cat['name'] = functions_plugins::trigger_change(
                'render_category_name',
                $cat['name'],
                'get_cat_display_name_cache'
            );

            if ($is_first) {
                $is_first = false;
            } else {
                $output .= '<span>' . $conf->level_separator . '</span>';
            }

            if (! isset($url) ||
                $single_link
            ) {
                $output .= $cat['name'];
            } elseif ($url === '') {
                $href = functions_url::add_url_params(
                    functions_url::make_index_url([
                        'category' => $cat,
                    ]),
                    $add_url_params
                );

                $output .= <<<HTML
                    <a href="{$href}">{$cat['name']}</a>
                    HTML;
            } else {
                $href = './' . $url . $category_id;
                $output .= <<<HTML
                    <a href="{$href}">{$cat['name']}</a>
                    HTML;
            }
        }

        if ($single_link &&
            isset($single_url)
        ) {
            $output .= '</a>';
        }

        return $output;
    }

    /**
     * Generates breadcrumb for a category.
     * @see get_cat_display_name()
     */
    public static function get_cat_display_name_from_id(
        int|string $cat_id,
        ?string $url = ''
    ): string {
        $cat_info = functions_category::get_cat_info($cat_id);
        return self::get_cat_display_name($cat_info['upper_names'], $url);
    }

    /**
     * Apply basic markdown transformations to a text.
     * newlines becomes br tags
     * _word_ becomes underline
     * /word/ becomes italic
     * *word* becomes bolded
     * urls becomes a tags
     */
    public static function render_comment_content(
        string $content
    ): string|null {
        $content = htmlspecialchars($content);
        $pattern = '/(https?:\/\/\S*)/';
        $replacement = '<a href="$1" rel="nofollow">$1</a>';
        $content = preg_replace($pattern, $replacement, $content);

        $content = nl2br($content);

        // replace _word_ by an underlined word
        $pattern = '/\b_(\S*)_\b/';
        $replacement = '<span style="text-decoration:underline;">$1</span>';
        $content = preg_replace($pattern, $replacement, $content);

        // replace *word* by a bolded word
        $pattern = '/\b\*(\S*)\*\b/';
        $replacement = '<span style="font-weight:bold;">$1</span>';
        $content = preg_replace($pattern, $replacement, $content);

        // replace /word/ by an italic word
        $pattern = '/\/(\S*)\/(\s)/';
        $replacement = '<span style="font-style:italic;">$1$2</span>';
        $content = preg_replace($pattern, $replacement, $content);

        // TODO : add a trigger

        return $content;
    }

    /**
     * Callback used for sorting by name.
     */
    public static function name_compare(
        array $a,
        array $b
    ): int {
        return strcmp(strtolower($a['name']), strtolower($b['name']));
    }

    /**
     * Callback used for sorting by name (slug) with cache.
     */
    public static function tag_alpha_compare(
        array $a,
        array $b
    ): int {
        global $cache;

        foreach ([$a, $b] as $tag) {
            if (! isset($cache[__FUNCTION__][$tag['name']])) {
                $cache[__FUNCTION__][$tag['name']] = functions::pwg_transliterate($tag['name']);
            }
        }

        return strcmp($cache[__FUNCTION__][$a['name']], $cache[__FUNCTION__][$b['name']]);
    }

    /**
     * Exits the current script (or redirect to login page if not logged).
     */
    public static function access_denied(): void
    {
        global $user, $conf;

        $login_url =
            functions_url::get_root_url() . 'identification.php?redirect='
            . urlencode(urlencode($_SERVER['REQUEST_URI']));

        if (isset($user) &&
            ! functions_user::is_a_guest()
        ) {
            self::set_status_header(401);

            echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
            echo '<div style="text-align:center;">' . functions::l10n('You are not authorized to access the requested page') . '<br>';
            echo '<a href="' . functions_url::get_root_url() . 'identification.php">' . functions::l10n('Identification') . '</a>&nbsp;';
            echo '<a href="' . functions_url::make_index_url() . '">' . functions::l10n('Home') . '</a></div>';
            echo str_repeat(' ', 512); //IE6 doesn't error output if below a size
            exit();
        } elseif (! $conf->guest_access &&
                  functions_user::is_a_guest()
        ) {
            functions::redirect_http($login_url);
        } else {
            functions::redirect_html($login_url);
        }
    }

    /**
     * Exits the current script with 403 code.
     * @param ?string $alternate_url redirect to this url
     * @throws SmartyException
     * @todo nice display if $template loaded
     */
    public static function page_forbidden(
        string $msg,
        ?string $alternate_url = null
    ): void {
        self::set_status_header(403);

        if ($alternate_url == null) {
            $alternate_url = functions_url::make_index_url();
        }

        $l10n_forbidden = functions::l10n('Forbidden');

        $html_content = <<<HTML
            <div style="text-align:left; margin-left:5em; margin-bottom:5em;">
              <h1 style="text-align:left; font-size:36px;">{$l10n_forbidden}</h1><br>
              {$msg}
            </div>
            HTML;

        functions::redirect_html($alternate_url, $html_content, 5);
    }

    /**
     * Exits the current script with 400 code.
     * @param ?string $alternate_url redirect to this url
     * @throws SmartyException
     * @todo nice display if $template loaded
     */
    public static function bad_request(
        string $msg,
        ?string $alternate_url = null
    ): void {
        self::set_status_header(400);

        if ($alternate_url == null) {
            $alternate_url = functions_url::make_index_url();
        }

        $l10n_bad_request = functions::l10n('Bad request');

        $html_content = <<<HTML
            <div style="text-align:left; margin-left:5em; margin-bottom:5em;">
              <h1 style="text-align:left; font-size:36px;">{$l10n_bad_request}</h1><br>
              {$msg}
            </div>
            HTML;

        functions::redirect_html($alternate_url, $html_content, 5);
    }

    /**
     * Exits the current script with 404 code.
     * @param ?string $alternate_url redirect to this url
     * @throws SmartyException
     * @todo nice display if $template loaded
     */
    public static function page_not_found(
        ?string $msg,
        ?string $alternate_url = null
    ): void {
        self::set_status_header(404);

        if ($alternate_url == null) {
            $alternate_url = functions_url::make_index_url();
        }

        $l10n_page_not_found = functions::l10n('Page not found');

        $html_content = <<<HTML
            <div style="text-align:left; margin-left:5em; margin-bottom:5em;">
              <h1 style="text-align:left; font-size:36px;">{$l10n_page_not_found}</h1><br>
              {$msg}
            </div>
            HTML;

        functions::redirect_html($alternate_url, $html_content, 5);
    }

    /**
     * Exits the current script with 500 code.
     * @todo nice display if $template loaded
     */
    public static function fatal_error(
        string $msg,
        ?string $title = null,
        bool $show_trace = true
    ): never {
        if (empty($title)) {
            $title = functions::l10n('Piwigo encountered a non recoverable error');
        }

        $btrace_msg = '';

        if ($show_trace) {
            $bt = debug_backtrace();
            $counter = count($bt);

            for ($i = 1; $i < $counter; $i++) {
                $class = isset($bt[$i]['class']) ? ($bt[$i]['class'] . '::') : '';
                $btrace_msg .= "#{$i}\t" . $class . $bt[$i]['function'] . ' ' . $bt[$i]['file'] . '(' . $bt[$i]['line'] . ")\n";
            }

            $btrace_msg = trim($btrace_msg);
            $msg .= "\n";
        }

        $display = <<<HTML
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
            <h1>{$title}</h1>
            <pre style="font-size: larger; background: white; color: red; padding: 1em; margin: 0; clear: both; display: block; width: auto; height: auto; overflow: auto;">
              <b>{$msg}</b>
              {$btrace_msg}
            </pre>
            HTML;

        self::set_status_header(500);
        echo $display . str_repeat(' ', 300); // IE6 doesn't error output if below a size

        ini_set('display_errors', false); // if possible turn off error display (we display it)

        error_reporting(E_ALL);
        trigger_error(strip_tags($msg) . $btrace_msg, E_USER_ERROR);
        exit(0); // just in case
    }

    /**
     * Returns the breadcrumb to be displayed above thumbnails on tag page.
     */
    public static function get_tags_content_title(): string
    {
        global $page;
        $title = '<a href="' . functions_url::get_root_url() . 'tags.php" title="' . functions::l10n('display available tags') . '">'
          . functions::l10n(count($page['tags']) > 1 ? 'Tags' : 'Tag')
          . '</a> ';
        $counter = count($page['tags']);

        for ($i = 0; $i < $counter; $i++) {
            $title .= $i > 0 ? ' + ' : '';

            $title .=
              '<a href="'
              . functions_url::make_index_url(
                  [
                      'tags' => [$page['tags'][$i]],
                  ]
              )
              . '" title="'
              . functions::l10n('display photos linked to this tag')
              . '">'
              . functions_plugins::trigger_change('render_tag_name', $page['tags'][$i]['name'], $page['tags'][$i])
              . '</a>';

            if (count($page['tags']) > 1) {
                $other_tags = $page['tags'];
                unset($other_tags[$i]);
                $remove_url = functions_url::make_index_url(
                    [
                        'tags' => $other_tags,
                    ]
                );

                $title .=
                  '<a id="TagsGroupRemoveTag" href="' . $remove_url . '" style="border:none;" title="'
                  . functions::l10n('remove this tag from the list')
                  . '"><img src="'
                    . functions_url::get_root_url() . functions::get_themeconf('icon_dir') . '/remove_s.png'
                  . '" alt="x" style="vertical-align:bottom;" >'
                  . '<span class="pwg-icon pwg-icon-close" ></span>'
                  . '<i class="fas fa-plus" aria-hidden="true"></i>'
                  . '</a>';
            }
        }

        return $title;
    }

    /**
     * Returns the breadcrumb to be displayed above thumbnails on combined categories page.
     */
    public static function get_combined_categories_content_title(): string
    {
        global $page;

        $title = functions::l10n('Albums') . ' ';

        $is_first = true;
        $all_categories = array_merge([$page['category']], $page['combined_categories']);

        foreach ($all_categories as $idx => $category) {
            $title .= $is_first ? '' : ' + ';
            $is_first = false;

            $title .= self::get_cat_display_name([$category]);

            if (count($all_categories) > 1) { // should be always the case
                $other_cats = $all_categories;
                unset($other_cats[$idx]);

                $params = [
                    'category' => array_shift($other_cats),
                ];

                if ($other_cats !== []) {
                    $params['combined_categories'] = $other_cats;
                }

                $remove_url = functions_url::make_index_url($params);

                $title .=
                  '<a id="TagsGroupRemoveTag" href="' . $remove_url . '" style="border:none;" title="'
                  . functions::l10n('remove this tag from the list')
                  . '"><img src="'
                    . functions_url::get_root_url() . functions::get_themeconf('icon_dir') . '/remove_s.png'
                  . '" alt="x" style="vertical-align:bottom;" >'
                  . '<span class="pwg-icon pwg-icon-close" ></span>'
                  . '</a>';
            }
        }

        return $title;
    }

    /**
     * Sets the http status header (200,401,...)
     * @param string $text for exotic http codes
     */
    public static function set_status_header(
        int $code,
        string $text = ''
    ): void {
        if (empty($text)) {
            switch ($code) {
                case 200: $text = 'OK';
                    break;

                case 301: $text = 'Moved permanently';
                    break;

                case 302: $text = 'Moved temporarily';
                    break;

                case 304: $text = 'Not modified';
                    break;

                case 400: $text = 'Bad request';
                    break;

                case 401: $text = 'Authorization required';
                    break;

                case 403: $text = 'Forbidden';
                    break;

                case 404: $text = 'Not found';
                    break;

                case 500: $text = 'Server error';
                    break;

                case 501: $text = 'Not implemented';
                    break;

                case 503: $text = 'Service unavailable';
                    break;
            }
        }

        $protocol = $_SERVER['SERVER_PROTOCOL'];

        if ($protocol != 'HTTP/1.1' &&
            $protocol != 'HTTP/1.0'
        ) {
            $protocol = 'HTTP/1.0';
        }

        header("{$protocol} {$code} {$text}", true, $code);
        functions_plugins::trigger_notify('set_status_header', $code, $text);
    }

    /**
     * Returns the category comment for rendering in html textual mode (subcatify)
     * This method is called by a trigger_notify()
     */
    public static function render_category_literal_description(
        ?string $desc
    ): string {
        if (! isset($desc)) {
            $desc = '';
        }

        return strip_tags($desc, '<span><p><a><br><b><i><small><big><strong><em>');
    }

    /**
     * Add known menubar blocks.
     * This method is called by a trigger_change()
     *
     * @param BlockManager[] $menu_ref_arr
     */
    public static function register_default_menubar_blocks(
        array $menu_ref_arr
    ): void {
        $menu = &$menu_ref_arr[0];

        if ($menu->get_id() != 'menubar') {
            return;
        }

        $menu->register_block(new RegisteredBlock('mbLinks', 'Links', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbCategories', 'Albums', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbTags', 'Related tags', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbSpecials', 'Specials', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbMenu', 'Menu', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbRelatedCategories', 'Related albums', 'piwigo'));

        // We hide the quick identification menu on the identification page. It
        // would be confusing.
        if (functions::script_basename() !== 'identification') {
            $menu->register_block(new RegisteredBlock('mbIdentification', 'Identification', 'piwigo'));
        }
    }

    /**
     * Returns display name for an element.
     * Returns 'name' if exists of name from 'file'.
     *
     * @param array<string, ?string> $info at least file or name
     */
    public static function render_element_name(
        array $info
    ): string {
        if (! empty($info['name'])) {
            return functions_plugins::trigger_change('render_element_name', $info['name'], $info);
        }

        return functions::get_name_from_file($info['file']);
    }

    /**
     * Returns display description for an element.
     *
     * @param array<string, int|string|null> $info at least comment
     * @param string $param used to identify the trigger
     */
    public static function render_element_description(
        array $info,
        string $param = ''
    ): string {
        if (! empty($info['comment'])) {
            return functions_plugins::trigger_change('render_element_description', $info['comment'], $param);
        }

        return '';
    }

    /**
     * Add info to the title of the thumbnail based on photo properties.
     *
     * @param array<string, int|string|null> $info hit, rating_score, nb_comments
     */
    public static function get_thumbnail_title(
        array $info,
        string $title,
        string $comment = ''
    ): string {
        global $conf, $user;

        $details = [];

        if (! empty($info['hit'])) {
            $details[] = functions::l10n('%d visits', $info['hit']);
        }

        if ($conf->rate &&
            ! empty($info['rating_score'])
        ) {
            $details[] = functions::l10n('rating score %s', $info['rating_score']);
        }

        if (isset($info['nb_comments']) &&
            $info['nb_comments'] != 0
        ) {
            $details[] = functions::l10n_dec('%d comment', '%d comments', $info['nb_comments']);
        }

        if ($details !== []) {
            $title .= ' (' . implode(', ', $details) . ')';
        }

        if (! empty($comment)) {
            $comment = strip_tags($comment);
            $title .= ' ' . substr($comment, 0, 100) . (strlen($comment) > 100 ? '...' : '');
        }

        $title = htmlspecialchars(strip_tags($title));
        $title = functions_plugins::trigger_change('get_thumbnail_title', $title, $info);

        return $title;
    }

    /**
     * Event handler to protect src image urls.
     */
    public static function get_src_image_url_protection_handler(
        string $url,
        SrcImage $src_image
    ): string {
        return functions_url::get_action_url($src_image->id, $src_image->is_original() !== 0 ? 'e' : 'r', false);
    }

    /**
     * Event handler to protect element urls.
     *
     * @param array $infos id, path
     */
    public static function get_element_url_protection_handler(
        string $url,
        array $infos
    ): string {
        global $conf;

        if ($conf->original_url_protection === 'images') { // protect only images and not other file types (for example large movies that we don't want to send through our file proxy)
            $ext = functions::get_extension($infos['path']);

            if (! in_array($ext, $conf->picture_ext)) {
                return $url;
            }
        }

        return functions_url::get_action_url($infos['id'], 'e', false);
    }

    /**
     * Sends to the template all messages stored in $page and in the session.
     */
    public static function flush_page_messages(): void
    {
        global $template, $page;

        if ($template->get_template_vars('page_refresh') === null) {
            foreach (['errors', 'infos', 'warnings', 'messages'] as $mode) {
                if (isset($_SESSION['page_' . $mode])) {
                    $page[$mode] = array_merge($page[$mode], $_SESSION['page_' . $mode]);
                    unset($_SESSION['page_' . $mode]);
                }

                if (count($page[$mode]) != 0) {
                    $template->assign($mode, $page[$mode]);
                }
            }
        }
    }
}
