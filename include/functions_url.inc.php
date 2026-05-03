<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+


/**
 * returns a prefix for each url link on displayed page
 * and return an empty string for current path
 * @return string
 */
function get_root_url(): string
{
    $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
    $rootPath = $page['root_path'] ?? null;
    if (is_string($rootPath) && $rootPath !== '') {
        return $rootPath;
    }
    $root_url = PHPWG_ROOT_PATH;
    if (str_starts_with($root_url, './')) {
        return substr($root_url, 2);
    }
    return $root_url;
}

/**
 * returns the absolute url to the root of PWG
 * @param boolean $with_scheme if false - does not add http://toto.com
 */
function get_absolute_root_url($with_scheme = true): string
{
    // TODO - add HERE the possibility to call PWG functions from external scripts

    // Support X-Forwarded-Proto header for HTTPS detection in PHP
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) and 'https' == $_SERVER['HTTP_X_FORWARDED_PROTO']) {
        $_SERVER['HTTPS'] = 'on';
    }

    $url = '';
    if ($with_scheme) {
        $is_https = false;
        if (isset($_SERVER['HTTPS']) && is_scalar($_SERVER['HTTPS']) &&
          ((strtolower((string) $_SERVER['HTTPS']) == 'on') or ($_SERVER['HTTPS'] == 1))) {
            $is_https = true;
            $url .= 'https://';
        } else {
            $url .= 'http://';
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $url .= is_scalar($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string) $_SERVER['HTTP_X_FORWARDED_HOST'] : '';
        } else {
            $url .= is_scalar($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';

            $url_port = null;

            if ('none' == \Piwigo\Config\Config::urlPort()) {
                // do nothing
            } elseif ('auto' == \Piwigo\Config\Config::urlPort()) {
                if ((!$is_https && $_SERVER['SERVER_PORT'] != 80) || ($is_https && $_SERVER['SERVER_PORT'] != 443)) {
                    $url_port = ':'.(is_scalar($_SERVER['SERVER_PORT']) ? (string) $_SERVER['SERVER_PORT'] : '');
                }
            } else {
                // we have a custom port
                $url_port = ':'.\Piwigo\Config\Config::urlPort();
            }

            if (!empty($url_port) and strrchr($url, ':') != $url_port) {
                $url .= $url_port;
            }
        }
    }
    $url .= cookie_path();
    return $url;
}

/**
 * adds one or more _GET style parameters to an url
 * example: add_url_params('/x', array('a'=>'b')) returns /x?a=b
 * add_url_params('/x?cat_id=10', array('a'=>'b')) returns /x?cat_id=10&amp;a=b
 * @param string $url
 * @param array $params
 * @return string
 */
/** @param array<mixed> $params */
function add_url_params(string $url, array $params, string $arg_separator = '&amp;'): string
{
    if (!empty($params)) {
        if (defined('IN_WS') and '&amp;' === $arg_separator) {
            $arg_separator = '&';
        }

        $is_first = true;
        foreach ($params as $param => $val) {
            if ($is_first) {
                $is_first = false;
                $url .= (!str_contains((string) $url, '?')) ? '?' : $arg_separator;
            } else {
                $url .= $arg_separator;
            }
            $url .= $param;
            if (isset($val)) {
                $url .= '='.(is_scalar($val) ? (string) $val : '');
            }
        }
    }
    return $url;
}

/**
 * build an index URL for a specific section
 *
 * @param array $params */
/** @param array<mixed> $params */
function make_index_url(array $params = []): string
{
    $url = get_root_url().'index';
    if (\Piwigo\Config\Config::phpExtensionInUrls()) {
        $url .= '.php';
    }
    if (\Piwigo\Config\Config::questionMarkInUrls()) {
        $url .= '?';
    }

    $url_before_params = $url;

    $url .= make_section_in_url($params);
    $url = add_well_known_params_in_url($url, $params);

    if ($url == $url_before_params) {
        $url = get_absolute_root_url(url_is_remote($url));
    }

    return $url;
}

/**
 * build an index URL with current page parameters, but with redefinitions
 * and removes.
 *
 * duplicate_index_url( array(
 *   'category' => array('id'=>12, 'name'=>'toto'),
 *   array('start')
 * ) will create an index URL on the current section (categories), but on
 * a redefined category and without the start URL parameter.
 *
 * @param array $redefined keys
 * @param array $removed keys
 */
/**
 * @param array<mixed> $redefined
 * @param string[] $removed
 */
function duplicate_index_url(array $redefined = [], array $removed = []): string
{
    return make_index_url(
        params_for_duplication($redefined, $removed)
    );
}

/**
 * returns $page global array with key redefined and key removed
 *
 * @param array $redefined keys
 * @param array $removed keys
 * @return array
 */
/**
 * @param array<mixed> $redefined
 * @param string[] $removed
 * @return array<mixed>
 */
function params_for_duplication(array $redefined, array $removed): array
{
    $params = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

    foreach ($removed as $param_key) {
        unset($params[$param_key]);
    }

    foreach ($redefined as $redefined_param => $redefined_value) {
        $params[$redefined_param] = $redefined_value;
    }

    return $params;
}

/**
 * create a picture URL with current page parameters, but with redefinitions
 * and removes. See duplicate_index_url.
 *
 * @param array $redefined keys
 * @param array $removed keys
 */
/**
 * @param array<mixed> $redefined
 * @param string[] $removed
 */
function duplicate_picture_url(array $redefined = [], array $removed = []): string
{
    return make_picture_url(
        params_for_duplication($redefined, $removed)
    );
}

/**
 * create a picture URL on a specific section for a specific picture
 *
 * @param array $params */
/** @param array<mixed> $params */
function make_picture_url(array $params): string
{
    $url = get_root_url().'picture';
    if (\Piwigo\Config\Config::phpExtensionInUrls()) {
        $url .= '.php';
    }
    if (\Piwigo\Config\Config::questionMarkInUrls()) {
        $url .= '?';
    }
    $url .= '/';
    switch (\Piwigo\Config\Config::pictureUrlStyle()) {
        case 'id-file':
            $url .= is_scalar($params['image_id']) ? (string) $params['image_id'] : '';
            if (isset($params['image_file'])) {
                $url .= '-'.str2url(get_filename_wo_extension(is_scalar($params['image_file']) ? (string) $params['image_file'] : ''));
            }
            break;
        case 'file':
            if (isset($params['image_file'])) {
                $fname_wo_ext = get_filename_wo_extension(is_scalar($params['image_file']) ? (string) $params['image_file'] : '');
                if (ord($fname_wo_ext) > ord('9') or !preg_match('/^\d+(-|$)/', $fname_wo_ext)) {
                    $url .= $fname_wo_ext;
                    break;
                }
            }
            // no break
        default:
            $url .= is_scalar($params['image_id']) ? (string) $params['image_id'] : '';
    }
    if (!isset($params['category'])) {// make urls shorter ...
        unset($params['flat']);
    }
    $url .= make_section_in_url($params);
    $url = add_well_known_params_in_url($url, $params);
    return $url;
}

/**
 *adds to the url the chronology and start parameters
*/
/** @param array<mixed> $params */
function add_well_known_params_in_url(string $url, array $params): string
{
    if (isset($params['chronology_field'])) {
        $url .= '/'. (is_scalar($params['chronology_field']) ? (string) $params['chronology_field'] : '');
        $url .= '-'. (is_scalar($params['chronology_style']) ? (string) $params['chronology_style'] : '');
        if (isset($params['chronology_view'])) {
            $url .= '-'. (is_scalar($params['chronology_view']) ? (string) $params['chronology_view'] : '');
        }
        if (!empty($params['chronology_date'])) {
            $url .= '-'. implode('-', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', is_array($params['chronology_date']) ? $params['chronology_date'] : []));
        }
    }

    if (isset($params['flat'])) {
        $url .= '/flat';
    }

    if (isset($params['start']) and $params['start'] > 0) {
        $url .= '/start-'.(is_scalar($params['start']) ? (string) $params['start'] : '');
    }
    return $url;
}

/**
 * return the section token of an index or picture URL.
 *
 * Depending on section, other parameters are required (see function code
 * for details)
 *
 * @param array $params */
/** @param array<mixed> $params */
function make_section_in_url(array $params): string
{
    $section_string = '';
    $section = @$params['section'];
    if (!isset($section)) {
        $section_of = [
          'category' => 'categories',
          'tags'     => 'tags',
          'list'     => 'list',
          'search'   => 'search',
          ];

        foreach ($section_of as $param => $s) {
            if (isset($params[$param])) {
                $section = $s;
            }
        }

        if (!isset($section)) {
            $section = 'none';
        }
    }

    switch ($section) {
        case 'categories':
            {
                if (!isset($params['category'])) {
                    $section_string .= '/categories';
                } else {
                    $cat = is_array($params['category']) ? $params['category'] : [];
                    isset($cat['name']) or trigger_error(
                        'make_section_in_url category name not set',
                        E_USER_WARNING
                    );

                    array_key_exists('permalink', $cat) or trigger_error(
                        'make_section_in_url category permalink not set',
                        E_USER_WARNING
                    );

                    $section_string .= '/category/';
                    if (empty($cat['permalink'])) {
                        $section_string .= is_scalar($cat['id']) ? (string) $cat['id'] : '';
                        if (\Piwigo\Config\Config::categoryUrlStyle() == 'id-name') {
                            $section_string .= '-'.str2url(is_scalar($cat['name']) ? (string) $cat['name'] : '');
                        }
                    } else {
                        $section_string .= is_scalar($cat['permalink']) ? (string) $cat['permalink'] : '';
                    }

                    if (isset($params['combined_categories'])) {
                        foreach ((array) $params['combined_categories'] as $category) {
                            if (!is_array($category)) {
                                continue;
                            }
                            $section_string .= '/';

                            if (empty($category['permalink'])) {
                                $section_string .= is_scalar($category['id']) ? (string) $category['id'] : '';
                                if (\Piwigo\Config\Config::categoryUrlStyle() == 'id-name') {
                                    $section_string .= '-'.str2url(is_scalar($category['name']) ? (string) $category['name'] : '');
                                }
                            } else {
                                $section_string .= is_scalar($category['permalink']) ? (string) $category['permalink'] : '';
                            }
                        }
                    }
                }

                break;
            }
        case 'tags':
            {
                $section_string .= '/tags';

                foreach ((array) $params['tags'] as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }
                    switch (\Piwigo\Config\Config::tagUrlStyle()) {
                        case 'id':
                            $section_string .= '/'. (is_scalar($tag['id']) ? (string) $tag['id'] : '');
                            break;
                        case 'tag':
                            if (isset($tag['url_name'])) {
                                $section_string .= '/'. (is_scalar($tag['url_name']) ? (string) $tag['url_name'] : '');
                                break;
                            }
                            // no break
                        default:
                            $section_string .= '/'. (is_scalar($tag['id']) ? (string) $tag['id'] : '');
                            if (isset($tag['url_name'])) {
                                $section_string .= '-'. (is_scalar($tag['url_name']) ? (string) $tag['url_name'] : '');
                            }
                    }
                }

                break;
            }
        case 'search':
            {
                $section_string .= '/search/'.(is_scalar($params['search']) ? (string) $params['search'] : '');
                break;
            }
        case 'list':
            {
                $section_string .= '/list/'.implode(',', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', is_array($params['list']) ? $params['list'] : []));
                break;
            }
        case 'none':
            {
                break;
            }
        default:
            {
                $section_string .= '/'.(is_scalar($section) ? (string) $section : '');
            }
    }

    return $section_string;
}

/**
 * the reverse of make_section_in_url
 * returns the 'section' (categories/tags/...) and the data associated with it
 *
 * Depending on section, other parameters are returned (category/tags/list/...)
 *
 *  array  url tokens to parse
 *  int  index in the array of url tokens; in/out
 */
/**
 * @param string[] $tokens
 * @return array<mixed>
 */
function parse_section_url(array $tokens, int &$next_token): array
{
    $page = ['hit_by' => [], 'combined_categories' => null];
    if (isset($tokens[$next_token]) and str_starts_with($tokens[$next_token], 'categor')) {
        $page['section'] = 'categories';
        $next_token++;

        $i = $next_token;
        $loop_counter = 0;

        while (isset($tokens[$next_token])) {
            if ($loop_counter++ > count($tokens) + 10) {
                die('infinite loop?');
            }

            if (
                str_starts_with($tokens[$next_token], 'created-')
                or str_starts_with($tokens[$next_token], 'posted-')
                or str_starts_with($tokens[$next_token], 'start-')
                or str_starts_with($tokens[$next_token], 'startcat-')
                or 'flat' == $tokens[$next_token]
            ) {
                break;
            }

            if (preg_match('/^(\d+)(?:-(.+))?$/', $tokens[$next_token], $matches)) {
                if (isset($matches[2])) {
                    $page['hit_by']['cat_url_name'] = $matches[2];
                }

                if (!isset($page['category'])) {
                    $page['category'] = $matches[1];
                } else {
                    if (!is_array($page['combined_categories'])) {
                        $page['combined_categories'] = [];
                    }
                    $page['combined_categories'][] = $matches[1];
                }
                $next_token++;
            } else {// try a permalink
                $maybe_permalinks = [];
                $current_token = $next_token;
                while (isset($tokens[$current_token])
                    and !str_starts_with($tokens[$current_token], 'created-')
                    and !str_starts_with($tokens[$current_token], 'posted-')
                    and !str_starts_with((string) $tokens[$next_token], 'start-')
                    and !str_starts_with((string) $tokens[$next_token], 'startcat-')
                    and $tokens[$current_token] != 'flat') {
                    if (empty($maybe_permalinks)) {
                        $maybe_permalinks[] = $tokens[$current_token];
                    } else {
                        $maybe_permalinks[] =
                            $maybe_permalinks[count($maybe_permalinks) - 1]
                            . '/' . $tokens[$current_token];
                    }
                    $current_token++;
                }

                if (count($maybe_permalinks)) {
                    $cat_id = get_cat_id_from_permalinks($maybe_permalinks, $perma_index);
                    if (isset($cat_id)) {
                        $next_token += $perma_index + 1;

                        if (!isset($page['category'])) {
                            $page['category'] = $cat_id;
                            $page['hit_by']['cat_permalink'] = $maybe_permalinks[$perma_index];
                        } else {
                            if (!is_array($page['combined_categories'])) {
                                $page['combined_categories'] = [];
                            }
                            $page['combined_categories'][] = $cat_id;
                        }
                    } else {
                        page_not_found(l10n('Permalink for album not found'));
                    }
                }
            }
        }

        if (isset($page['category'])) {
            $result = get_cat_info($page['category']);
            if (empty($result)) {
                page_not_found(l10n('Requested album does not exist'));
            }
            $page['category'] = $result;
        }

        if (isset($page['combined_categories'])) {
            $combined_categories = [];

            foreach ($page['combined_categories'] as $cat_id) {
                $result = get_cat_info($cat_id);
                if (empty($result)) {
                    page_not_found(l10n('Requested album does not exist'));
                }

                $combined_categories[] = $result;
            }

            $page['combined_categories'] = $combined_categories;
        }
    } elseif ('tags' == @$tokens[$next_token]) {
        $page['section'] = 'tags';
        $page['tags'] = [];

        $next_token++;
        $i = $next_token;

        $requested_tag_ids = [];
        $requested_tag_url_names = [];

        while (isset($tokens[$i])) {
            if (str_starts_with($tokens[$i], 'created-')
                 or str_starts_with($tokens[$i], 'posted-')
                 or str_starts_with($tokens[$i], 'start-')) {
                break;
            }

            if (\Piwigo\Config\Config::tagUrlStyle() != 'tag' and preg_match('/^(\d+)(?:-(.*)|)$/', $tokens[$i], $matches)) {
                $requested_tag_ids[] = $matches[1];
            } else {
                $requested_tag_url_names[] = $tokens[$i];
            }
            $i++;
        }
        $next_token = $i;

        if (empty($requested_tag_ids) && empty($requested_tag_url_names)) {
            bad_request('at least one tag required');
        }

        $page['tags'] = find_tags($requested_tag_ids, $requested_tag_url_names);
        if (empty($page['tags'])) {
            page_not_found(l10n('Requested tag does not exist'), get_root_url().'tags.php');
        }
    } elseif ('favorites' == @$tokens[$next_token]) {
        $page['section'] = 'favorites';
        $next_token++;
    } elseif ('most_visited' == @$tokens[$next_token]) {
        $page['section'] = 'most_visited';
        $next_token++;
    } elseif ('best_rated' == @$tokens[$next_token]) {
        $page['section'] = 'best_rated';
        $next_token++;
    } elseif ('recent_pics' == @$tokens[$next_token]) {
        $page['section'] = 'recent_pics';
        $next_token++;
    } elseif ('recent_cats' == @$tokens[$next_token]) {
        $page['section'] = 'recent_cats';
        $next_token++;
    } elseif ('search' == @$tokens[$next_token]) {
        $page['section'] = 'search';
        $next_token++;

        preg_match('/^(psk-\d{8}-[a-zA-Z0-9]{10})$/', (string) @$tokens[$next_token], $matches);
        if (!isset($matches[1])) {
            preg_match('/(\d+)/', (string) @$tokens[$next_token], $matches);
            if (!isset($matches[1])) {
                bad_request('search identifier is missing');
                return $page;
            }
        }
        $page['search'] = $matches[1];
        $next_token++;
    } elseif ('list' == @$tokens[$next_token]) {
        $page['section'] = 'list';
        $next_token++;

        $page['list'] = [];

        // No pictures
        if (empty($tokens[$next_token])) {
            // Add dummy element list
            $page['list'][] = -1;
        }
        // With pictures list
        else {
            if (!preg_match('/^\d+(,\d+)*$/', (string) $tokens[$next_token])) {
                bad_request('wrong format on list GET parameter');
            }
            foreach (explode(',', (string) $tokens[$next_token]) as $image_id) {
                $page['list'][] = $image_id;
            }
        }
        $next_token++;
    }
    return $page;
}

/**
 * the reverse of add_well_known_params_in_url
 * parses start, flat and chronology from url tokens
 * @return list<string>[]|string[]|true[]
*/
/**
 * @param string[] $tokens
 * @return array<mixed>
 */
function parse_well_known_params_url(array $tokens, int &$i): array
{
    $page = [];
    while (isset($tokens[$i])) {
        if ('flat' == $tokens[$i]) {
            // indicate a special list of images
            $page['flat'] = true;
        } elseif (str_starts_with($tokens[$i], 'created-') or str_starts_with($tokens[$i], 'posted-')) {
            $chronology_tokens = explode('-', $tokens[$i]);

            $page['chronology_field'] = $chronology_tokens[0];

            array_shift($chronology_tokens);
            $page['chronology_style'] = $chronology_tokens[0];

            if (!in_array($page['chronology_style'], ['monthly', 'weekly'])) {
                fatal_error('bad chronology field (style)');
            }

            array_shift($chronology_tokens);
            if (count($chronology_tokens) > 0) {
                if ('list' == $chronology_tokens[0] or
                    'calendar' == $chronology_tokens[0]) {
                    $page['chronology_view'] = $chronology_tokens[0];
                    array_shift($chronology_tokens);
                }
                $page['chronology_date'] = $chronology_tokens;

                foreach ($page['chronology_date'] as $date_token) {
                    // each date part must be an integer (number of the year, number of the month, number of the week or number of the day)
                    if (!preg_match('/^(\d+|any)$/', $date_token)) {
                        fatal_error('bad chronology field (date)');
                    }
                }
            }
        } elseif (preg_match('/^start-(\d+)/', $tokens[$i], $matches)) {
            $page['start'] = $matches[1];
        } elseif (preg_match('/^startcat-(\d+)/', $tokens[$i], $matches)) {
            $page['startcat'] = $matches[1];
        }
        $i++;
    }
    return $page;
}


/**
 *  int  image id
 *  string  one of 'e' (element), 'r' (representative)
 */
function get_action_url(int|string $id, string $what_part, bool $download): string
{
    $params = [
          'id' => (int) $id,
          'part' => $what_part,
        ];
    if ($download) {
        $params['download'] = null;
    }

    return add_url_params(get_root_url().'action.php', $params);
}

/*
 * @param element_info $array containing element information from db;
 * at least 'id', 'path' should be present
 */
/** @param array<string,mixed> $element_info */
function get_element_url(array $element_info): string
{
    $url = is_scalar($element_info['path']) ? (string) $element_info['path'] : '';
    if (!url_is_remote($url)) {
        $result = embellish_url(get_root_url().$url);
        return is_string($result) ? $result : '';
    }
    return $url;
}


/**
 * Indicate to build url with full path
 *

 */
function set_make_full_url(): void
{
    $page = &$GLOBALS['page'];
    if (!is_array($page)) {
        $page = [];
    }
    $save = isset($page['save_root_path']) && is_array($page['save_root_path'])
        ? $page['save_root_path']
        : null;
    if ($save === null) {
        $newSave = [];
        if (isset($page['root_path'])) {
            $newSave['path'] = $page['root_path'];
        }
        $newSave['count'] = 1;
        $page['save_root_path'] = $newSave;
        $page['root_path'] = get_absolute_root_url();
    } else {
        $count = is_numeric($save['count'] ?? null) ? (int) $save['count'] : 0;
        $save['count'] = $count + 1;
        $page['save_root_path'] = $save;
    }
}

/**
 * Restore old parameter to build url with full path
 *

 */
function unset_make_full_url(): void
{
    $page = &$GLOBALS['page'];
    if (!is_array($page)) {
        $page = [];
    }
    $save = isset($page['save_root_path']) && is_array($page['save_root_path'])
        ? $page['save_root_path']
        : null;
    if ($save === null) {
        return;
    }
    $count = is_numeric($save['count'] ?? null) ? (int) $save['count'] : 0;
    if ($count == 1) {
        if (isset($save['path'])) {
            $page['root_path'] = $save['path'];
        } else {
            unset($page['root_path']);
        }
        unset($page['save_root_path']);
    } else {
        $save['count'] = $count - 1;
        $page['save_root_path'] = $save;
    }
}

/**
 * Embellish the url argument
 *
 * @param $url
 *  string
 */
/**
 * @param string|string[] $url
 * @return string|string[]
 */
function embellish_url(string|array $url): string|array
{
    if (is_array($url)) {
        return array_map(fn (string $u): string => is_string($r = embellish_url($u)) ? $r : $u, $url);
    }
    $url = str_replace('/./', '/', $url);
    while (($dotdot = strpos($url, '/../', 1)) !== false) {
        $before = strrpos($url, '/', -(strlen($url) - $dotdot + 1));
        if ($before !== false) {
            $url = substr_replace($url, '', $before, $dotdot - $before + 3);
        } else {
            break;
        }
    }
    return $url;
}

/**
 * Returns the 'home page' of this gallery
 */
function get_gallery_home_url(): string
{
    if (!empty(\Piwigo\Config\Config::galleryUrl())) {
        if (url_is_remote(\Piwigo\Config\Config::galleryUrl()) or \Piwigo\Config\Config::galleryUrl()[0] == '/') {
            return \Piwigo\Config\Config::galleryUrl();
        }
        return get_root_url().\Piwigo\Config\Config::galleryUrl();
    } else {
        return make_index_url();
    }
}

/**
 * returns $_SERVER['QUERY_STRING'] whithout keys given in parameters
 *
 * @param string[] $rejects
 * @param boolean $escape escape *&* to *&amp;*
 * @returns string
 */
function get_query_string_diff($rejects = [], $escape = true): string
{
    if (empty($_SERVER['QUERY_STRING'])) {
        return '';
    }

    parse_str(is_scalar($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '', $vars);

    $vars = array_diff_key($vars, array_flip($rejects));

    return '?' . http_build_query($vars, '', $escape ? '&amp;' : '&');
}

/**
 * returns true if the url is absolute (begins with http)
 *
 * @param string $url
 * @returns boolean
 */
function url_is_remote($url): bool
{
    if (str_starts_with($url, 'http://')
      or str_starts_with($url, 'https://')) {
        return true;
    }
    return false;
}

/**
 * List favorite image_ids of the current user.
 * @since 13
 */
/** @return array<int,true> */
function get_user_favorites(): array
{
    if (is_a_guest()) {
        return [];
    }

    $query = '
SELECT
    image_id,
    1 as fake_value
  FROM '.FAVORITES_TABLE.'
  WHERE user_id = '.\Piwigo\Users\CurrentUser::get()->id.'
';

    $raw = query2array($query, 'image_id', 'fake_value');
    $result = [];
    foreach ($raw as $image_id => $val) {
        $result[(int) $image_id] = true;
    }
    return $result;
}
