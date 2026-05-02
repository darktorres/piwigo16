<?php

declare(strict_types=1);

use Piwigo\Image\SrcImage;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\RegisteredBlock;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @package functions\html
 */
/**
 * Generates breadcrumb from categories list.
 * Categories string returned contains categories as given in the input
 * array $cat_informations. $cat_informations array must be an array
 * of array( id=>?, name=>?, permalink=>?). If url input parameter is null,
 * returns only the categories name without links.
 *
 * @param array $cat_informations
 * @param string|null $url
 */
/** @param array<mixed> $cat_informations */
function get_cat_display_name(array $cat_informations, ?string $url = ''): string
{
    //$output = '<a href="'.get_absolute_root_url().\Piwigo\Core\Config::get('home_page').'">'.l10n('Home').'</a>';
    $output = '';
    $is_first = true;

    foreach ($cat_informations as $cat) {
        if (!is_array($cat)) {
            trigger_error(
                'get_cat_display_name wrong type for category ',
                E_USER_WARNING
            );
            continue;
        }

        $cat['name'] = trigger_change(
            'render_category_name',
            is_scalar($cat['name']) ? (string) $cat['name'] : '',
            'get_cat_display_name'
        );

        if ($is_first) {
            $is_first = false;
        } else {
            $output .= \Piwigo\Core\Config::levelSeparator();
        }

        if (!isset($url)) {
            $output .= $cat['name'];
        } elseif ($url == '') {
            $output .= '<a href="'
                  .make_index_url(
                      [
                        'category' => $cat,
                        ]
                  )
                  .'">';
            $output .= $cat['name'].'</a>';
        } else {
            $output .= '<a href="'.PHPWG_ROOT_PATH.$url.(is_scalar($cat['id']) ? (string) $cat['id'] : '').'">';
            $output .= $cat['name'].'</a>';
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
 */
function get_cat_display_name_cache(
    string $uppercats,
    ?string $url = '',
    bool $single_link = false,
    ?string $link_class = null,
    ?string $auth_key = null
): string {
    global $cache;

    $add_url_params = [];
    if (isset($auth_key)) {
        $add_url_params['auth'] = $auth_key;
    }

    if (!isset($cache['cat_names'])) {
        $query = '
SELECT id, name, permalink
  FROM '.CATEGORIES_TABLE.'
;';
        $cache['cat_names'] = query2array($query, 'id');
    }

    $output = '';
    if ($single_link) {
        $uppercats_array = explode(',', $uppercats);
        $single_url = add_url_params(get_root_url().$url.array_pop($uppercats_array), $add_url_params);
        $output .= '<a href="'.$single_url.'"';
        if (isset($link_class)) {
            $output .= ' class="'.$link_class.'"';
        }
        $output .= '>';
    }
    $is_first = true;
    foreach (explode(',', $uppercats) as $category_id) {
        $cat = $cache['cat_names'][$category_id];
        if (!is_array($cat)) {
            continue;
        }

        $cat['name'] = trigger_change(
            'render_category_name',
            is_scalar($cat['name']) ? (string) $cat['name'] : '',
            'get_cat_display_name_cache'
        );

        if ($is_first) {
            $is_first = false;
        } else {
            $output .= '<span>'.\Piwigo\Core\Config::levelSeparator().'</span>';
        }

        $cat_name = $cat['name'];
        if (!isset($url) or $single_link) {
            $output .= $cat_name;
        } elseif ($url == '') {
            $output .= '
<a href="'
            .add_url_params(
                make_index_url(
                    [
            'category' => $cat,
            ]
                ),
                $add_url_params
            )
            .'">'.$cat_name.'</a>';
        } else {
            $output .= '
<a href="'.PHPWG_ROOT_PATH.$url.$category_id.'">'.$cat_name.'</a>';
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
    $upper_names = $cat_info['upper_names'] ?? [];
    return get_cat_display_name(is_array($upper_names) ? $upper_names : [], $url);
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
 * @return string
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
 */
/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function name_compare(array $a, array $b): int
{
    return strcmp(strtolower(is_scalar($a['name']) ? (string) $a['name'] : ''), strtolower(is_scalar($b['name']) ? (string) $b['name'] : ''));
}

/**
 * Callback used for sorting by name (slug) with cache.
 */
/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function tag_alpha_compare(array $a, array $b): int
{
    global $cache;

    foreach ([$a, $b] as $tag) {
        $tag_name = is_scalar($tag['name']) ? (string) $tag['name'] : '';
        if (!isset($cache[__FUNCTION__][$tag_name])) {
            $cache[__FUNCTION__][$tag_name] = pwg_transliterate($tag_name);
        }
    }

    $a_name = is_scalar($a['name']) ? (string) $a['name'] : '';
    $b_name = is_scalar($b['name']) ? (string) $b['name'] : '';
    return strcmp((string) $cache[__FUNCTION__][$a_name], (string) $cache[__FUNCTION__][$b_name]);
}

/**
 * Exits the current script.
 */
function access_denied(): void
{
    global $user;

    if (isset($user) and !is_a_guest()) {
        set_status_header(401);

        echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<link rel="shortcut icon" type="image/x-icon" href="themes/default/icon/favicon.ico">
<div style="display: flex; justify-content: center;align-items: center;height: 100vh;margin: 0;color: #3C3C3C;font-family: \'Open Sans\', sans-serif;font-size: 20px;font-style: normal;font-weight: 600;line-height: normal;">
  <div style="text-align:center;">
    <img src="themes/default/icon/warning-triangle.svg" alt="warning-triangle" >
    <p style="max-width: 400px; margin-top 20px;">'.l10n('You are not authorized to access the requested page').'</p>
    <a href="'.make_index_url().'" style="display: inline-block;padding: 10px 20px;margin: 10px;margin-top: 50px;border-radius: 7px;cursor: pointer;width: 150px;background-color: #F77000;color: #fff;text-decoration: none;border: 2px solid #F77000;">'.l10n('Home').'</a>
  </div>
</div>';
        exit();
    }

    redirect_http(get_root_url().'identification.php?redirect='.urlencode(urlencode(is_scalar($_SERVER['REQUEST_URI'] ?? null) ? (string)$_SERVER['REQUEST_URI'] : '')));
}


/**
 * Exits the current script with 403 code.
 * @todo nice display if $template loaded
 *
 * @param string|null $alternate_url redirect to this url
 */
function page_forbidden(string $msg, $alternate_url = null): void
{
    set_status_header(403);
    if ($alternate_url == null) {
        $alternate_url = make_index_url();
    }
    redirect_html(
        $alternate_url,
        '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">'.l10n('Forbidden').'</h1><br>'
.$msg.'</div>',
        5
    );
}

/**
 * Exits the current script with 400 code.
 * @todo nice display if $template loaded
 *
 * @param string|null $alternate_url redirect to this url
 */
function bad_request(string $msg, $alternate_url = null): void
{
    set_status_header(400);
    if ($alternate_url == null) {
        $alternate_url = make_index_url();
    }
    redirect_html(
        $alternate_url,
        '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">'.l10n('Bad request').'</h1><br>'
.$msg.'</div>',
        5
    );
}

/**
 * Exits the current script with 404 code.
 * @todo nice display if $template loaded
 *
 * @param string|null $alternate_url redirect to this url
 */
function page_not_found(?string $msg, $alternate_url = null): void
{
    set_status_header(404);
    if ($alternate_url == null) {
        $alternate_url = make_index_url();
    }
    redirect_html(
        $alternate_url,
        '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">'.l10n('Page not found').'</h1><br>'
.$msg.'</div>',
        5
    );
}

/**
 * Exits the current script with 500 code.
 * @todo nice display if $template loaded
 *
 * @param string|null $title
 * @param bool $show_trace
 */
function fatal_error(string $msg, $title = null, $show_trace = true): never
{
    if (empty($title)) {
        $title = l10n('Piwigo encountered a non recoverable error');
    }

    $btrace_msg = '';
    if ($show_trace and function_exists('debug_backtrace')) {
        $bt = debug_backtrace();
        for ($i = 1; $i < count($bt); $i++) {
            $class = isset($bt[$i]['class']) ? (@$bt[$i]['class'].'::') : '';
            $btrace_msg .= "#$i\t".$class.$bt[$i]['function'].' '.($bt[$i]['file'] ?? '').'('.($bt[$i]['line'] ?? '').")\n";
        }
        $btrace_msg = trim($btrace_msg);
        $msg .= "\n";
    }

    $display = "<meta http-equiv='Content-Type' content='text/html; charset=utf-8'>
<h1>$title</h1>
<pre style='font-size:larger;background:white;color:red;padding:1em;margin:0;clear:both;display:block;width:auto;height:auto;overflow:auto'>
<b>$msg</b>
$btrace_msg
</pre>\n";

    @set_status_header(500);
    echo $display.str_repeat(' ', 300); //IE6 doesn't error output if below a size

    if (function_exists('ini_set')) {// if possible turn off error display (we display it)
        ini_set('display_errors', false);
    }
    error_reporting(E_ALL);
    throw new \RuntimeException(strip_tags($msg).$btrace_msg);
}

/**
 * Returns the breadcrumb to be displayed above thumbnails on tag page.
 */
function get_tags_content_title(): string
{
    global $page;
    $title = '<a href="'.get_root_url().'tags.php" title="'.l10n('display available tags').'">'
      . l10n(count($page['tags']) > 1 ? 'Tags' : 'Tag')
      . '</a> ';

    return $title;
}

/**
 * Returns the breadcrumb to be displayed above thumbnails on combined categories page.
 */
function get_combined_categories_content_title(): string
{
    global $page;

    $title = l10n('Albums').' ';

    $is_first = true;
    $all_categories = array_merge([$page['category']], $page['combined_categories']);
    foreach ($all_categories as $idx => $category) {
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
              '<a id="TagsGroupRemoveTag" href="'.$remove_url.'" style="border:none;" title="'
              .l10n('remove this tag from the list')
              .'"><img src="'
                .get_root_url().(is_string(get_themeconf('icon_dir')) ? get_themeconf('icon_dir') : '').'/remove_s.png'
              .'" alt="x" style="vertical-align:bottom;" >'
              .'<span class="pwg-icon pwg-icon-close" ></span>'
              .'</a>';
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
    $protocolRaw = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0';
    $protocol = is_string($protocolRaw) ? $protocolRaw : 'HTTP/1.0';
    if (('HTTP/1.1' != $protocol) && ('HTTP/1.0' != $protocol)) {
        $protocol = 'HTTP/1.0';
    }

    header("$protocol $code $text", true, $code);
    trigger_notify('set_status_header', $code, $text);
}

/**
 * Returns the category comment for rendering in html textual mode (subcatify)
 * This method is called by a trigger_notify()
 *
 * @param string $desc
 */
function render_category_literal_description($desc): string
{
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
    $menu = & $menu_ref_arr[0];
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
 * @param array $info at least file or name
 * @return string
 */
/** @param array<string, mixed> $info */
function render_element_name(array $info): string
{
    if (!empty($info['name'])) {
        return (string) trigger_change('render_element_name', is_scalar($info['name']) ? (string) $info['name'] : '', $info);
    }
    return get_name_from_file(is_string($info['file'] ?? null) ? $info['file'] : '');
}

/**
 * Returns display description for an element.
 *
 * @param array $info at least comment
 * @param string $param used to identify the trigger
 * @return string
 */
/** @param array<string, mixed> $info */
function render_element_description(array $info, string $param = ''): string
{
    if (!empty($info['comment'])) {
        return (string) trigger_change('render_element_description', is_scalar($info['comment']) ? (string) $info['comment'] : '', $param);
    }
    return '';
}

/**
 * Add info to the title of the thumbnail based on photo properties.
 *
 * @param array $info hit, rating_score, nb_comments
 * @param string $title
 * @param string $comment
 * @return string
 */
/** @param array<string, mixed> $info */
function get_thumbnail_title(array $info, string $title, string $comment = ''): string
{
    global $user;

    $details = [];

    if (!empty($info['hit'])) {
        $details[] = l10n('%d visits', $info['hit']);
    }

    if (\Piwigo\Core\Config::rateEnabled() and !empty($info['rating_score'])) {
        $details[] = l10n('rating score %s', $info['rating_score']);
    }

    if (isset($info['nb_comments']) and $info['nb_comments'] != 0) {
        $details[] = l10n_dec('%d comment', '%d comments', is_numeric($info['nb_comments']) ? (int) $info['nb_comments'] : 0);
    }

    if (count($details) > 0) {
        $title .= ' ('.implode(', ', $details).')';
    }

    if (!empty($comment)) {
        $comment = strip_tags($comment);
        $title .= ' '.substr($comment, 0, 100).(strlen($comment) > 100 ? '...' : '');
    }

    $title = htmlspecialchars(strip_tags($title));
    $title = trigger_change('get_thumbnail_title', $title, $info);

    return $title;
}

/**
 * Event handler to protect src image urls.
 *
 * @param string $url
 * @param SrcImage $src_image
 * @return string
 */
function get_src_image_url_protection_handler($url, $src_image)
{
    return get_action_url($src_image->id, $src_image->is_original() ? 'e' : 'r', false);
}

/**
 * Event handler to protect element urls.
 *
 * @param string $url
 * @param array $infos id, path
 * @return string
 */
/** @param array<string, mixed> $infos */
function get_element_url_protection_handler(string $url, array $infos): string
{
    if ('images' == \Piwigo\Core\Config::originalUrlProtection()) {// protect only images and not other file types (for example large movies that we don't want to send through our file proxy)
        $ext = get_extension(is_string($infos['path'] ?? null) ? $infos['path'] : '');
        if (!in_array($ext, \Piwigo\Core\Config::pictureExtensions())) {
            return $url;
        }
    }
    return get_action_url(is_int($infos['id'] ?? null) || is_string($infos['id'] ?? null) ? $infos['id'] : 0, 'e', false);
}

/**
 * Sends to the template all messages stored in $page and in the session.
 */
function flush_page_messages(): void
{
    global $template, $page;
    if ($template->get_template_vars('page_refresh') === null) {
        foreach (['errors','infos','warnings', 'messages'] as $mode) {
            if (isset($_SESSION['page_'.$mode])) {
                $sessionArr = is_array($_SESSION['page_'.$mode]) ? $_SESSION['page_'.$mode] : [];
                $pageArr = is_array($page[$mode] ?? null) ? $page[$mode] : [];
                $page[$mode] = array_merge($pageArr, $sessionArr);
                unset($_SESSION['page_'.$mode]);
            }

            if (!empty($page[$mode])) {
                $template->assign($mode, $page[$mode]);
            }
        }
    }
}

/**
 * pwg_nl2br is useful for PHP 5.2 which doesn't accept more than 1
 * parameter on nl2br() (and anyway the second parameter of nl2br does not
 * match what Piwigo gives.
 */
function pwg_nl2br(string $string): string
{
    if (empty($string)) {
        return $string;
    }

    return nl2br((string) $string);
}
