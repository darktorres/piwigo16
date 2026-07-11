<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Image\SrcImage;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\RegisteredBlock;
use Piwigo\Template\Template;

/**
 * Generates breadcrumb from categories list.
 * Categories string returned contains categories as given in the input
 * array $cat_informations. $cat_informations array must be an array
 * of array( id=>?, name=>?, permalink=>?). If url input parameter is null,
 * returns only the categories name without links.
 *
 * @param array<int, array<string, mixed>> $cat_informations
 * @param string|null $url
 */
function get_cat_display_name($cat_informations, $url = ''): string
{
    /** @var array<string, mixed> $conf */
    global $conf;
    $level_separator = is_string($conf['level_separator']) ? $conf['level_separator'] : ' / ';

    // $output = '<a href="'.get_absolute_root_url().$conf['home_page'].'">'.l10n('Home').'</a>';
    $output = '';
    $is_first = true;

    foreach ($cat_informations as $cat) {
        $cat['name'] = trigger_change(
            'render_category_name',
            $cat['name'],
            'get_cat_display_name'
        );
        // trigger_change()'s own return type is mixed; category names are
        // always strings, but a misbehaving handler shouldn't propagate a
        // non-string value into the markup built below.
        if (! is_string($cat['name'])) {
            $cat['name'] = '';
        }

        if ($is_first) {
            $is_first = false;
        } else {
            $output .= $level_separator;
        }

        if (! isset($url)) {
            $output .= $cat['name'];
        } elseif ($url == '') {
            $output .= '<a href="'
                  . make_index_url(
                      [
                          'category' => $cat,
                      ]
                  )
                  . '">';
            $output .= $cat['name'] . '</a>';
        } else {
            $cat_id = is_scalar($cat['id']) ? (string) $cat['id'] : '';
            $output .= '<a href="' . PHPWG_ROOT_PATH . $url . $cat_id . '">';
            $output .= $cat['name'] . '</a>';
        }
    }
    return $output;
}

/**
 * Generates breadcrumb from categories list using a cache.
 * @see get_cat_display_name()
 *
 * @param string $uppercats
 * @param string|null $url
 * @param bool $single_link
 * @param string|null $link_class
 * @param string|null $auth_key
 */
function get_cat_display_name_cache(
    $uppercats,
    $url = '',
    $single_link = false,
    $link_class = null,
    $auth_key = null
): string {
    /**
     * @var array<string, mixed> $cache
     * @var array<string, mixed> $conf
     */
    global $cache, $conf;
    $level_separator = is_string($conf['level_separator']) ? $conf['level_separator'] : ' / ';

    $add_url_params = [];
    if (isset($auth_key)) {
        $add_url_params['auth'] = $auth_key;
    }

    if (! isset($cache['cat_names'])) {
        $query = '
SELECT id, name, permalink
  FROM ' . CATEGORIES_TABLE . '
;';
        $cache['cat_names'] = query2array($query, 'id');
    }
    // Narrowed once here (fix pattern #7): $cache is array<string, mixed>,
    // proving $cache is array-like does not prove $cache['cat_names'] is
    // also array-like, since the declared value type is mixed.
    $cat_names = is_array($cache['cat_names']) ? $cache['cat_names'] : [];

    $output = '';
    if ($single_link) {
        $uppercats_array = explode(',', $uppercats);
        $single_url = add_url_params(get_root_url() . $url . array_pop($uppercats_array), $add_url_params);
        $output .= '<a href="' . $single_url . '"';
        if (isset($link_class)) {
            $output .= ' class="' . $link_class . '"';
        }
        $output .= '>';
    }
    $is_first = true;
    foreach (explode(',', $uppercats) as $category_id) {
        $cat = $cat_names[$category_id] ?? null;
        $cat = is_array($cat) ? $cat : [];

        $cat['name'] = trigger_change(
            'render_category_name',
            $cat['name'],
            'get_cat_display_name_cache'
        );
        // trigger_change()'s own return type is mixed; category names are
        // always strings, but a misbehaving handler shouldn't propagate a
        // non-string value into the markup built below.
        if (! is_string($cat['name'])) {
            $cat['name'] = '';
        }

        if ($is_first) {
            $is_first = false;
        } else {
            $output .= '<span>' . $level_separator . '</span>';
        }

        if (! isset($url) or $single_link) {
            $output .= $cat['name'];
        } elseif ($url == '') {
            $output .= '
<a href="'
            . add_url_params(
                make_index_url(
                    [
                        'category' => $cat,
                    ]
                ),
                $add_url_params
            )
            . '">' . $cat['name'] . '</a>';
        } else {
            $output .= '
<a href="' . PHPWG_ROOT_PATH . $url . $category_id . '">' . $cat['name'] . '</a>';
        }
    }

    if ($single_link) {
        $output .= '</a>';
    }

    return $output;
}

/**
 * Generates breadcrumb for a category.
 * @see get_cat_display_name()
 *
 * @param int $cat_id
 * @param string|null $url
 */
function get_cat_display_name_from_id($cat_id, $url = ''): string
{
    $cat_info = get_cat_info($cat_id);
    // $cat_id isn't existence-validated by callers (WS/URL param) -- a
    // stale/forged id falls back to an empty breadcrumb.
    $upper_names = $cat_info['upper_names'] ?? [];
    // get_cat_info()'s return type is the generic array<string, mixed>, but
    // its 'upper_names' key (the only producer, verified in
    // functions_category.inc.php) is always built as a list of category-row
    // arrays with string keys (id, name, permalink) -- never anything else.
    $upper_names = is_array($upper_names) ? $upper_names : [];
    /** @var array<int, array<string, mixed>> $upper_names */
    return get_cat_display_name($upper_names, $url);
}

/**
 * Apply basic markdown transformations to a text.
 * newlines becomes br tags
 * _word_ becomes underline
 * /word/ becomes italic
 * *word* becomes bolded
 * urls becomes a tags
 *
 * @param string $content
 */
function render_comment_content($content): string|null
{
    $content = htmlspecialchars($content);
    $pattern = '/(https?:\/\/\S*)/';
    $replacement = '<a href="$1" rel="nofollow">$1</a>';
    $content = preg_replace($pattern, $replacement, $content);

    $content = nl2br((string) $content);

    // replace _word_ by an underlined word
    $pattern = '/\b_(\S*)_\b/';
    $replacement = '<span style="text-decoration:underline;">$1</span>';
    $content = preg_replace($pattern, $replacement, $content);

    // replace *word* by a bolded word
    $pattern = '/\b\*(\S*)\*\b/';
    $replacement = '<span style="font-weight:bold;">$1</span>';
    $content = preg_replace($pattern, $replacement, (string) $content);

    // replace /word/ by an italic word
    $pattern = "/\/(\S*)\/(\s)/";
    $replacement = '<span style="font-style:italic;">$1$2</span>';
    $content = preg_replace($pattern, $replacement, (string) $content);

    // TODO : add a trigger

    return $content;
}

/**
 * Callback used for sorting by name.
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function name_compare(array $a, array $b): int
{
    $name_a = is_string($a['name'] ?? null) ? $a['name'] : '';
    $name_b = is_string($b['name'] ?? null) ? $b['name'] : '';

    return strcmp(strtolower($name_a), strtolower($name_b));
}

/**
 * Callback used for sorting by name (slug) with cache.
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function tag_alpha_compare(array $a, array $b): int
{
    /** @var array<string, mixed> $cache */
    global $cache;

    $name_a = is_string($a['name'] ?? null) ? $a['name'] : '';
    $name_b = is_string($b['name'] ?? null) ? $b['name'] : '';

    // Narrowed once here (fix pattern #7): $cache is array<string, mixed>,
    // so $cache[__FUNCTION__] is still mixed even after $cache is typed.
    $transliterated = is_array($cache[__FUNCTION__] ?? null) ? $cache[__FUNCTION__] : [];

    foreach ([$name_a, $name_b] as $tag_name) {
        // pwg_transliterate() always returns string, so a cached entry that
        // isn't a string was never written by this loop and must be
        // (re)computed -- a real runtime guard equivalent to the original
        // isset() check (fix pattern #6).
        if (! is_string($transliterated[$tag_name] ?? null)) {
            $transliterated[$tag_name] = pwg_transliterate($tag_name);
        }
    }

    $cache[__FUNCTION__] = $transliterated;

    $translit_a = is_string($transliterated[$name_a] ?? null) ? $transliterated[$name_a] : pwg_transliterate($name_a);
    $translit_b = is_string($transliterated[$name_b] ?? null) ? $transliterated[$name_b] : pwg_transliterate($name_b);

    return strcmp($translit_a, $translit_b);
}

/**
 * Exits the current script.
 */
function access_denied(): never
{
    global $user, $conf;

    if (isset($user) and ! is_a_guest()) {
        set_status_header(401);

        echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<link rel="shortcut icon" type="image/x-icon" href="themes/default/icon/favicon.ico">
<div style="display: flex; justify-content: center;align-items: center;height: 100vh;margin: 0;color: #3C3C3C;font-family: \'Open Sans\', sans-serif;font-size: 20px;font-style: normal;font-weight: 600;line-height: normal;">
  <div style="text-align:center;">
    <img src="themes/default/icon/warning-triangle.svg" alt="warning-triangle" >
    <p style="max-width: 400px; margin-top 20px;">' . l10n('You are not authorized to access the requested page') . '</p>
    <a href="' . make_index_url() . '" style="display: inline-block;padding: 10px 20px;margin: 10px;margin-top: 50px;border-radius: 7px;cursor: pointer;width: 150px;background-color: #F77000;color: #fff;text-decoration: none;border: 2px solid #F77000;">' . l10n('Home') . '</a>
  </div>
</div>';
        exit();
    }

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $request_uri = is_string($request_uri) ? $request_uri : '';
    redirect_http(get_root_url() . 'identification.php?redirect=' . urlencode(urlencode($request_uri)));
}

/**
 * Exits the current script with 403 code.
 * @todo nice display if $template loaded
 *
 * @param string $msg
 * @param string|null $alternate_url redirect to this url
 */
function page_forbidden($msg, $alternate_url = null): never
{
    set_status_header(403);
    if ($alternate_url == null) {
        $alternate_url = make_index_url();
    }
    redirect_html(
        $alternate_url,
        '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . l10n('Forbidden') . '</h1><br>'
. $msg . '</div>',
        5
    );
}

/**
 * Exits the current script with 400 code.
 * @todo nice display if $template loaded
 *
 * @param string $msg
 * @param string|null $alternate_url redirect to this url
 */
function bad_request($msg, $alternate_url = null): never
{
    set_status_header(400);
    if ($alternate_url == null) {
        $alternate_url = make_index_url();
    }
    redirect_html(
        $alternate_url,
        '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . l10n('Bad request') . '</h1><br>'
. $msg . '</div>',
        5
    );
}

/**
 * Exits the current script with 404 code.
 * @todo nice display if $template loaded
 *
 * @param string|null $msg null is treated the same as '' below (string
 *   concatenation); comments.php passes null when comments are disabled
 * @param string|null $alternate_url redirect to this url
 */
function page_not_found($msg, $alternate_url = null): never
{
    set_status_header(404);
    if ($alternate_url == null) {
        $alternate_url = make_index_url();
    }
    redirect_html(
        $alternate_url,
        '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . l10n('Page not found') . '</h1><br>'
. $msg . '</div>',
        5
    );
}

/**
 * Exits the current script with 500 code.
 * @todo nice display if $template loaded
 *
 * @param string $msg
 * @param string|null $title
 * @param bool $show_trace
 */
function fatal_error($msg, $title = null, $show_trace = true): never
{
    if (empty($title)) {
        $title = l10n('Piwigo encountered a non recoverable error');
    }

    $btrace_msg = '';
    if ($show_trace and function_exists('debug_backtrace')) {
        $bt = debug_backtrace();
        for ($i = 1; $i < count($bt); $i++) {
            $class = isset($bt[$i]['class']) ? (@$bt[$i]['class'] . '::') : '';
            $btrace_msg .= "#{$i}\t" . $class . $bt[$i]['function'] . ' ' . ($bt[$i]['file'] ?? '') . '(' . ($bt[$i]['line'] ?? '') . ")\n";
        }
        $btrace_msg = trim($btrace_msg);
        $msg .= "\n";
    }

    $display = "<meta http-equiv='Content-Type' content='text/html; charset=utf-8'>
<h1>{$title}</h1>
<pre style='font-size:larger;background:white;color:red;padding:1em;margin:0;clear:both;display:block;width:auto;height:auto;overflow:auto'>
<b>{$msg}</b>
{$btrace_msg}
</pre>\n";

    @set_status_header(500);
    echo $display . str_repeat(' ', 300); // IE6 doesn't error output if below a size

    if (function_exists('ini_set')) {// if possible turn off error display (we display it)
        ini_set('display_errors', false);
    }
    error_reporting(E_ALL);
    trigger_error(strip_tags($msg) . $btrace_msg, E_USER_ERROR);
    // Genuinely reachable, not just defensive: include/error_collector.inc.php
    // installs a set_error_handler() that intercepts E_USER_ERROR and returns
    // true (suppressing PHP's normal fatal-and-terminate behavior), so
    // trigger_error() above can actually return here when that handler is
    // active (installed for every real request via common.inc.php) — static
    // analysis has no way to know set_error_handler() changes this.
    // @phpstan-ignore deadCode.unreachable
    die(0);
}

/**
 * Returns the breadcrumb to be displayed above thumbnails on tag page.
 */
function get_tags_content_title(): string
{
    /** @var array<string, mixed> $page */
    global $page;

    $tags = is_array($page['tags'] ?? null) ? $page['tags'] : [];

    $title = '<a href="' . get_root_url() . 'tags.php" title="' . l10n('display available tags') . '">'
      . l10n(count($tags) > 1 ? 'Tags' : 'Tag')
      . '</a> ';

    return $title;
}

/**
 * Returns the breadcrumb to be displayed above thumbnails on combined categories page.
 */
function get_combined_categories_content_title(): string
{
    /** @var array<string, mixed> $page */
    global $page;

    $title = l10n('Albums') . ' ';

    // Narrowed once here (fix pattern #7): $page is array<string, mixed>,
    // so $page['combined_categories'] is still mixed even after $page is
    // typed.
    $combined_categories = is_array($page['combined_categories'] ?? null) ? $page['combined_categories'] : [];
    $is_first = true;
    $all_categories = array_merge([$page['category']], $combined_categories);
    foreach ($all_categories as $idx => $category) {
        $category = is_array($category) ? $category : [];
        /** @var array<string, mixed> $category */
        $title .= $is_first ? '' : ' + ';
        $is_first = false;

        $title .= get_cat_display_name([$category]);

        if (count($all_categories) > 1) { // should be always the case
            $other_cats = $all_categories;
            unset($other_cats[$idx]);

            $params = [
                'category' => array_shift($other_cats),
            ];

            if (count($other_cats) > 0) {
                $params['combined_categories'] = $other_cats;
            }
            $remove_url = make_index_url($params);

            $title .=
              '<a id="TagsGroupRemoveTag" href="' . $remove_url . '" style="border:none;" title="'
              . l10n('remove this tag from the list')
              . '"><img src="'
                . get_root_url() . get_themeconf('icon_dir') . '/remove_s.png'
              . '" alt="x" style="vertical-align:bottom;" >'
              . '<span class="pwg-icon pwg-icon-close" ></span>'
              . '</a>';
        }
    }

    return $title;
}

/**
 * Sets the http status header (200,401,...)
 * @param int $code
 * @param string $text for exotic http codes
 */
function set_status_header($code, $text = ''): void
{
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
    $protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';
    $protocol = is_string($protocol) ? $protocol : '';
    if (($protocol != 'HTTP/1.1') && ($protocol != 'HTTP/1.0')) {
        $protocol = 'HTTP/1.0';
    }

    header("{$protocol} {$code} {$text}", true, $code);
    trigger_notify('set_status_header', $code, $text);
}

/**
 * Returns the category comment for rendering in html textual mode (subcatify)
 * This method is called by a trigger_notify()
 */
function render_category_literal_description(?string $desc): string
{
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
function register_default_menubar_blocks(array $menu_ref_arr): void
{
    $menu = &$menu_ref_arr[0];
    if ($menu->get_id() != 'menubar') {
        return;
    }
    $menu->register_block(new RegisteredBlock('mbLinks', 'Links', 'piwigo'));
    $menu->register_block(new RegisteredBlock('mbCategories', 'Albums', 'piwigo'));
    $menu->register_block(new RegisteredBlock('mbTags', 'Tags', 'piwigo'));
    $menu->register_block(new RegisteredBlock('mbSpecials', 'Specials', 'piwigo'));
    $menu->register_block(new RegisteredBlock('mbMenu', 'Menu', 'piwigo'));
    $menu->register_block(new RegisteredBlock('mbRelatedCategories', 'Related albums', 'piwigo'));

    // We hide the quick identification menu on the identification page. It
    // would be confusing.
    if (script_basename() != 'identification') {
        $menu->register_block(new RegisteredBlock('mbIdentification', 'Identification', 'piwigo'));
    }
}

/**
 * Returns display name for an element.
 * Returns 'name' if exists of name from 'file'.
 *
 * @param array<string, mixed> $info at least file or name
 */
function render_element_name(array $info): string
{
    if (! empty($info['name']) && is_string($info['name'])) {
        $rendered_name = trigger_change('render_element_name', $info['name'], $info);
        // trigger_change()'s own return type is mixed; fall back to the
        // pre-trigger name if a misbehaving handler returns something else.
        return is_string($rendered_name) ? $rendered_name : $info['name'];
    }
    $filename = $info['file'] ?? null;
    return get_name_from_file(is_string($filename) ? $filename : '');
}

/**
 * Returns display description for an element.
 *
 * @param array<string, mixed> $info at least comment
 * @param string $param used to identify the trigger
 */
function render_element_description(array $info, $param = ''): string
{
    if (! empty($info['comment']) && is_string($info['comment'])) {
        $rendered_comment = trigger_change('render_element_description', $info['comment'], $param);
        // trigger_change()'s own return type is mixed; fall back to the
        // pre-trigger comment if a misbehaving handler returns something
        // else.
        return is_string($rendered_comment) ? $rendered_comment : $info['comment'];
    }
    return '';
}

/**
 * Add info to the title of the thumbnail based on photo properties.
 *
 * @param array<string, mixed> $info hit, rating_score, nb_comments
 * @param string $title
 * @param string $comment
 */
function get_thumbnail_title(array $info, $title, $comment = ''): string
{
    /** @var array<string, mixed> $conf */
    global $conf, $user;

    $details = [];

    if (! empty($info['hit'])) {
        $details[] = l10n('%d visits', $info['hit']);
    }

    if ((bool) $conf['rate'] and ! empty($info['rating_score'])) {
        $details[] = l10n('rating score %s', $info['rating_score']);
    }

    if (isset($info['nb_comments']) and is_numeric($info['nb_comments']) and (int) $info['nb_comments'] !== 0) {
        $details[] = l10n_dec('%d comment', '%d comments', (int) $info['nb_comments']);
    }

    if (count($details) > 0) {
        $title .= ' (' . implode(', ', $details) . ')';
    }

    if (! empty($comment)) {
        $comment = strip_tags($comment);
        $title .= ' ' . substr($comment, 0, 100) . (strlen($comment) > 100 ? '...' : '');
    }

    $title = htmlspecialchars(strip_tags($title));
    $rendered_title = trigger_change('get_thumbnail_title', $title, $info);
    // trigger_change()'s own return type is mixed; fall back to the
    // pre-trigger title if a misbehaving handler returns something else.
    return is_string($rendered_title) ? $rendered_title : $title;
}

/**
 * Event handler to protect src image urls.
 *
 * @param string $url
 * @param SrcImage $src_image
 */
function get_src_image_url_protection_handler($url, $src_image): string
{
    return get_action_url($src_image->id, $src_image->is_original() ? 'e' : 'r', false);
}

/**
 * Event handler to protect element urls.
 *
 * @param string $url
 * @param array<string, mixed> $infos id, path
 * @return string
 */
function get_element_url_protection_handler($url, array $infos)
{
    /** @var array<string, mixed> $conf */
    global $conf;
    if ($conf['original_url_protection'] == 'images') {// protect only images and not other file types (for example large movies that we don't want to send through our file proxy)
        $path = $infos['path'] ?? null;
        $ext = get_extension(is_string($path) ? $path : null);
        $picture_ext = is_array($conf['picture_ext'] ?? null) ? $conf['picture_ext'] : [];
        if (! in_array($ext, $picture_ext)) {
            return $url;
        }
    }
    $id = $infos['id'] ?? '';
    $id = is_int($id) || is_string($id) ? $id : '';
    return get_action_url($id, 'e', false);
}

/**
 * Sends to the template all messages stored in $page and in the session.
 */
function flush_page_messages(): void
{
    /**
     * @var Template $template
     * @var array<string, mixed> $page
     */
    global $template, $page;
    if ($template->get_template_vars('page_refresh') === null) {
        foreach (['errors', 'infos', 'warnings', 'messages'] as $mode) {
            // Narrowed once here (fix pattern #7): $page is
            // array<string, mixed>, so $page[$mode] is still mixed even
            // after $page is typed.
            $page_messages = is_array($page[$mode] ?? null) ? $page[$mode] : [];

            // Every writer of $_SESSION['page_*'] elsewhere in the codebase
            // (comments.php, picture.php, admin/batch_manager*.php, ...)
            // guards with is_array() before appending, so this mirrors that
            // same invariant instead of trusting the superglobal's mixed
            // element type.
            if (isset($_SESSION['page_' . $mode]) and is_array($_SESSION['page_' . $mode])) {
                $page_messages = array_merge($page_messages, $_SESSION['page_' . $mode]);
                unset($_SESSION['page_' . $mode]);
            }
            $page[$mode] = $page_messages;

            if (! empty($page_messages)) {
                $template->assign($mode, $page_messages);
            }
        }
    }
}

/**
 * pwg_nl2br is useful for PHP 5.2 which doesn't accept more than 1
 * parameter on nl2br() (and anyway the second parameter of nl2br does not
 * match what Piwigo gives.
 *
 * @param array<int|string, mixed>|null|int|float|false|string $string
 * @return array<int|string, mixed>|null|int|float|false|string
 */
function pwg_nl2br($string): array|null|int|float|false|string
{
    if (empty($string)) {
        return $string;
    }

    if (is_array($string)) {
        return $string;
    }

    return nl2br((string) $string);
}
