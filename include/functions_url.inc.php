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
function get_root_url()
{
    global $page;
    if (($root_url = @$page['root_path']) == null) {// TODO - add HERE the possibility to call PWG functions from external scripts
        $root_url = PHPWG_ROOT_PATH;
        if (str_starts_with($root_url, './')) {
            return substr($root_url, 2);
        }
    }
    return $root_url;
}

/**
 * returns the absolute url to the root of PWG
 * @param bool $with_scheme if false - does not add http://toto.com
 */
function get_absolute_root_url($with_scheme = true): string
{
    global $conf;
    // TODO - add HERE the possibility to call PWG functions from external scripts

    // Support X-Forwarded-Proto header for HTTPS detection in PHP
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) and $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
        $_SERVER['HTTPS'] = 'on';
    }

    $url = '';
    if ($with_scheme) {
        $is_https = false;
        if (isset($_SERVER['HTTPS']) &&
          ((strtolower((string) $_SERVER['HTTPS']) == 'on') or ($_SERVER['HTTPS'] == 1))) {
            $is_https = true;
            $url .= 'https://';
        } else {
            $url .= 'http://';
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $url .= $_SERVER['HTTP_X_FORWARDED_HOST'];
        } else {
            $url .= $_SERVER['HTTP_HOST'];

            $url_port = null;

            if ($conf['url_port'] == 'none') {
                // do nothing
            } elseif ($conf['url_port'] == 'auto') {
                if ((! $is_https && $_SERVER['SERVER_PORT'] != 80) || ($is_https && $_SERVER['SERVER_PORT'] != 443)) {
                    $url_port = ':' . $_SERVER['SERVER_PORT'];
                }
            } else {
                // we have a custom port
                $url_port = ':' . $conf['url_port'];
            }

            if (! empty($url_port) and strrchr($url, ':') != $url_port) {
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
 * @param array<int|string, mixed> $params
 * @return string
 */
function add_url_params($url, $params, string $arg_separator = '&amp;')
{
    if (! empty($params)) {
        if (defined('IN_WS') and $arg_separator === '&amp;') {
            $arg_separator = '&';
        }

        $is_first = true;
        foreach ($params as $param => $val) {
            if ($is_first) {
                $is_first = false;
                $url .= (! str_contains($url, '?')) ? '?' : $arg_separator;
            } else {
                $url .= $arg_separator;
            }
            $url .= $param;
            if (isset($val)) {
                $url .= '=' . $val;
            }
        }
    }
    return $url;
}

/**
 * build an index URL for a specific section
 *
 * @param array<string, mixed> $params
 */
function make_index_url(array $params = []): string
{
    global $conf;
    $url = get_root_url() . 'index';
    if ($conf['php_extension_in_urls']) {
        $url .= '.php';
    }
    if ($conf['question_mark_in_urls']) {
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
 * @param array<string, mixed> $redefined keys
 * @param array<int, string> $removed keys
 */
function duplicate_index_url($redefined = [], $removed = []): string
{
    return make_index_url(
        params_for_duplication($redefined, $removed)
    );
}

/**
 * returns $page global array with key redefined and key removed
 *
 * @param array<string, mixed> $redefined keys
 * @param array<int, string> $removed keys
 * @return array<string, mixed>
 */
function params_for_duplication($redefined, $removed)
{
    global $page;

    $params = $page;

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
 * @param array<string, mixed> $redefined keys
 * @param array<int, string> $removed keys
 */
function duplicate_picture_url($redefined = [], $removed = []): string
{
    return make_picture_url(
        params_for_duplication($redefined, $removed)
    );
}

/**
 * create a picture URL on a specific section for a specific picture
 *
 * @param array<string, mixed> $params
 */
function make_picture_url(array $params): string
{
    global $conf;

    $url = get_root_url() . 'picture';
    if ($conf['php_extension_in_urls']) {
        $url .= '.php';
    }
    if ($conf['question_mark_in_urls']) {
        $url .= '?';
    }
    $url .= '/';
    switch ($conf['picture_url_style']) {
        case 'id-file':
            $url .= $params['image_id'];
            if (isset($params['image_file'])) {
                $url .= '-' . str2url(get_filename_wo_extension($params['image_file']));
            }
            break;
        case 'file':
            if (isset($params['image_file'])) {
                $fname_wo_ext = get_filename_wo_extension($params['image_file']);
                if (ord($fname_wo_ext) > ord('9') or ! preg_match('/^\d+(-|$)/', $fname_wo_ext)) {
                    $url .= $fname_wo_ext;
                    break;
                }
            }
            // no break
        default:
            $url .= $params['image_id'];
    }
    if (! isset($params['category'])) {// make urls shorter ...
        unset($params['flat']);
    }
    $url .= make_section_in_url($params);
    $url = add_well_known_params_in_url($url, $params);
    return $url;
}

/**
 *adds to the url the chronology and start parameters
 *
 * @param array<string, mixed> $params
 */
function add_well_known_params_in_url(string $url, array $params): string
{
    if (isset($params['chronology_field'])) {
        $url .= '/' . $params['chronology_field'];
        $url .= '-' . $params['chronology_style'];
        if (isset($params['chronology_view'])) {
            $url .= '-' . $params['chronology_view'];
        }
        if (! empty($params['chronology_date'])) {
            $url .= '-' . implode('-', $params['chronology_date']);
        }
    }

    if (isset($params['flat'])) {
        $url .= '/flat';
    }

    if (isset($params['start']) and $params['start'] > 0) {
        $url .= '/start-' . $params['start'];
    }
    return $url;
}

/**
 * return the section token of an index or picture URL.
 *
 * Depending on section, other parameters are required (see function code
 * for details)
 *
 * @param array<string, mixed> $params
 */
function make_section_in_url(array $params): string
{
    global $conf;
    $section_string = '';
    $section = @$params['section'];
    if (! isset($section)) {
        $section_of = [
            'category' => 'categories',
            'tags' => 'tags',
            'list' => 'list',
            'search' => 'search',
        ];

        foreach ($section_of as $param => $s) {
            if (isset($params[$param])) {
                $section = $s;
            }
        }

        if (! isset($section)) {
            $section = 'none';
        }
    }

    switch ($section) {
        case 'categories':

            if (! isset($params['category'])) {
                $section_string .= '/categories';
            } else {
                isset($params['category']['name']) or trigger_error(
                    'make_section_in_url category name not set',
                    E_USER_WARNING
                );

                array_key_exists('permalink', $params['category']) or trigger_error(
                    'make_section_in_url category permalink not set',
                    E_USER_WARNING
                );

                $section_string .= '/category/';
                if (empty($params['category']['permalink'])) {
                    $section_string .= $params['category']['id'];
                    if ($conf['category_url_style'] == 'id-name') {
                        $section_string .= '-' . str2url($params['category']['name']);
                    }
                } else {
                    $section_string .= $params['category']['permalink'];
                }

                if (isset($params['combined_categories'])) {
                    foreach ($params['combined_categories'] as $category) {
                        $section_string .= '/';

                        if (empty($category['permalink'])) {
                            $section_string .= $category['id'];
                            if ($conf['category_url_style'] == 'id-name') {
                                $section_string .= '-' . str2url($category['name']);
                            }
                        } else {
                            $section_string .= $category['permalink'];
                        }
                    }
                }
            }

            break;

        case 'tags':

            $section_string .= '/tags';

            foreach ($params['tags'] as $tag) {
                switch ($conf['tag_url_style']) {
                    case 'id':
                        $section_string .= '/' . $tag['id'];
                        break;
                    case 'tag':
                        if (isset($tag['url_name'])) {
                            $section_string .= '/' . $tag['url_name'];
                            break;
                        }
                        // no break
                    default:
                        $section_string .= '/' . $tag['id'];
                        if (isset($tag['url_name'])) {
                            $section_string .= '-' . $tag['url_name'];
                        }
                }
            }

            break;

        case 'search':

            $section_string .= '/search/' . $params['search'];
            break;

        case 'list':

            $section_string .= '/list/' . implode(',', $params['list']);
            break;

        case 'none':

            break;

        default:

            $section_string .= '/' . $section;

    }

    return $section_string;
}

/**
 * the reverse of make_section_in_url
 * returns the 'section' (categories/tags/...) and the data associated with it
 *
 * Depending on section, other parameters are returned (category/tags/list/...)
 *
 * @param string[] $tokens of url tokens to parse
 * @param int $next_token the index in the array of url tokens; in/out
 * @return array<string, mixed>
 */
function parse_section_url(array $tokens, &$next_token): array
{
    $page = [];
    if (isset($tokens[$next_token]) and str_starts_with($tokens[$next_token], 'categor')) {
        $page['section'] = 'categories';
        $next_token++;

        $i = $next_token;
        $loop_counter = 0;

        // Built up across loop iterations via dedicated locals (not $page
        // sub-keys) — $category/$combined_category_ids hold raw ids
        // (int|numeric-string) here, only becoming full category-info
        // arrays once assigned into $page after the loop. Mixing both
        // shapes under the same $page keys across loop back-edges is what
        // previously defeated PHPStan's array-shape tracking.
        /** @var int|numeric-string|null $category */
        $category = null;
        /** @var array<int, int|numeric-string> $combined_category_ids */
        $combined_category_ids = [];
        /** @var array{cat_url_name?: string, cat_permalink?: string} $hit_by */
        $hit_by = [];

        while (isset($tokens[$next_token])) {
            if ($loop_counter++ > count($tokens) + 10) {
                die('infinite loop?');
            }

            if (
                str_starts_with($tokens[$next_token], 'created-')
                or str_starts_with($tokens[$next_token], 'posted-')
                or str_starts_with($tokens[$next_token], 'start-')
                or str_starts_with($tokens[$next_token], 'startcat-')
                or $tokens[$next_token] == 'flat'
            ) {
                break;
            }

            if (preg_match('/^(\d+)(?:-(.+))?$/', $tokens[$next_token], $matches)) {
                if (isset($matches[2])) {
                    $hit_by['cat_url_name'] = $matches[2];
                }

                if ($category === null) {
                    $category = $matches[1];
                } else {
                    $combined_category_ids[] = $matches[1];
                }
                $next_token++;
            } else {// try a permalink
                $maybe_permalinks = [];
                $current_token = $next_token;
                while (isset($tokens[$current_token])
                    and ! str_starts_with($tokens[$current_token], 'created-')
                    and ! str_starts_with($tokens[$current_token], 'posted-')
                    and ! str_starts_with($tokens[$current_token], 'start-')
                    and ! str_starts_with($tokens[$current_token], 'startcat-')
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

                        if ($category === null) {
                            $category = $cat_id;
                            $hit_by['cat_permalink'] = $maybe_permalinks[$perma_index];
                        } else {
                            $combined_category_ids[] = $cat_id;
                        }
                    } else {
                        page_not_found(l10n('Permalink for album not found'));
                    }
                }
            }
        }

        if (! empty($hit_by)) {
            $page['hit_by'] = $hit_by;
        }

        if ($category !== null) {
            $result = get_cat_info((int) $category);
            if (empty($result)) {
                page_not_found(l10n('Requested album does not exist'));
            }
            $page['category'] = $result;
        }

        if (! empty($combined_category_ids)) {
            $combined_categories = [];

            foreach ($combined_category_ids as $cat_id) {
                $result = get_cat_info((int) $cat_id);
                if (empty($result)) {
                    page_not_found(l10n('Requested album does not exist'));
                }

                $combined_categories[] = $result;
            }

            $page['combined_categories'] = $combined_categories;
        }
    } elseif (@$tokens[$next_token] == 'tags') {
        global $conf;

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

            if ($conf['tag_url_style'] != 'tag' and preg_match('/^(\d+)(?:-(.*)|)$/', $tokens[$i], $matches)) {
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
            page_not_found(l10n('Requested tag does not exist'), get_root_url() . 'tags.php');
        }
    } elseif (@$tokens[$next_token] == 'favorites') {
        $page['section'] = 'favorites';
        $next_token++;
    } elseif (@$tokens[$next_token] == 'most_visited') {
        $page['section'] = 'most_visited';
        $next_token++;
    } elseif (@$tokens[$next_token] == 'best_rated') {
        $page['section'] = 'best_rated';
        $next_token++;
    } elseif (@$tokens[$next_token] == 'recent_pics') {
        $page['section'] = 'recent_pics';
        $next_token++;
    } elseif (@$tokens[$next_token] == 'recent_cats') {
        $page['section'] = 'recent_cats';
        $next_token++;
    } elseif (@$tokens[$next_token] == 'search') {
        $page['section'] = 'search';
        $next_token++;

        preg_match('/^(psk-\d{8}-[a-zA-Z0-9]{10})$/', (string) @$tokens[$next_token], $matches);
        if (! isset($matches[1])) {
            preg_match('/(\d+)/', (string) @$tokens[$next_token], $matches);
            if (! isset($matches[1])) {
                bad_request('search identifier is missing');
            }
        }
        $page['search'] = $matches[1];
        $next_token++;
    } elseif (@$tokens[$next_token] == 'list') {
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
            if (! preg_match('/^\d+(,\d+)*$/', (string) $tokens[$next_token])) {
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
 * @param string[] $tokens
 * @return list<string>[]|string[]|true[]
 */
function parse_well_known_params_url(array $tokens, int &$i): array
{
    $page = [];
    while (isset($tokens[$i])) {
        if ($tokens[$i] == 'flat') {
            // indicate a special list of images
            $page['flat'] = true;
        } elseif (str_starts_with($tokens[$i], 'created-') or str_starts_with($tokens[$i], 'posted-')) {
            $chronology_tokens = explode('-', $tokens[$i]);

            $page['chronology_field'] = $chronology_tokens[0];

            array_shift($chronology_tokens);
            $page['chronology_style'] = $chronology_tokens[0];

            if (! in_array($page['chronology_style'], ['monthly', 'weekly'])) {
                fatal_error('bad chronology field (style)');
            }

            array_shift($chronology_tokens);
            if (count($chronology_tokens) > 0) {
                if ($chronology_tokens[0] == 'list' or
                    $chronology_tokens[0] == 'calendar') {
                    $page['chronology_view'] = $chronology_tokens[0];
                    array_shift($chronology_tokens);
                }
                $page['chronology_date'] = $chronology_tokens;

                foreach ($page['chronology_date'] as $date_token) {
                    // each date part must be an integer (number of the year, number of the month, number of the week or number of the day)
                    if (! preg_match('/^(\d+|any)$/', $date_token)) {
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
 * @param int|string $id image id
 * @param string $what_part one of 'e' (element), 'r' (representative)
 */
function get_action_url($id, $what_part, bool $download): string
{
    $params = [
        'id' => $id,
        'part' => $what_part,
    ];
    if ($download) {
        $params['download'] = null;
    }

    return add_url_params(get_root_url() . 'action.php', $params);
}

/**
 * @param array<string, mixed> $element_info containing element information from db;
 * at least 'id', 'path' should be present
 */
function get_element_url(array $element_info): mixed
{
    $url = $element_info['path'];
    if (! url_is_remote($url)) {
        $url = embellish_url(get_root_url() . $url);
    }
    return $url;
}

/**
 * Indicate to build url with full path
 */
function set_make_full_url(): void
{
    global $page;

    if (! isset($page['save_root_path'])) {
        if (isset($page['root_path'])) {
            $page['save_root_path']['path'] = $page['root_path'];
        }
        $page['save_root_path']['count'] = 1;
        $page['root_path'] = get_absolute_root_url();
    } else {
        ++$page['save_root_path']['count'];
    }
}

/**
 * Restore old parameter to build url with full path
 */
function unset_make_full_url(): void
{
    global $page;

    if (isset($page['save_root_path'])) {
        if ($page['save_root_path']['count'] == 1) {
            if (isset($page['save_root_path']['path'])) {
                $page['root_path'] = $page['save_root_path']['path'];
            } else {
                unset($page['root_path']);
            }
            unset($page['save_root_path']);
        } else {
            --$page['save_root_path']['count'];
        }
    }
}

/**
 * Embellish the url argument
 *
 * @param string $url
 */
function embellish_url($url): string
{
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
function get_gallery_home_url(): mixed
{
    global $conf;
    if (! empty($conf['gallery_url'])) {
        if (url_is_remote($conf['gallery_url']) or $conf['gallery_url'][0] == '/') {
            return $conf['gallery_url'];
        }
        return get_root_url() . $conf['gallery_url'];
    } else {
        return make_index_url();
    }
}

/**
 * returns $_SERVER['QUERY_STRING'] whithout keys given in parameters
 *
 * @param string[] $rejects
 * @param bool $escape escape *&* to *&amp;*
 */
function get_query_string_diff($rejects = [], $escape = true): string
{
    if (empty($_SERVER['QUERY_STRING'])) {
        return '';
    }

    parse_str((string) $_SERVER['QUERY_STRING'], $vars);

    $vars = array_diff_key($vars, array_flip($rejects));

    return '?' . http_build_query($vars, '', $escape ? '&amp;' : '&');
}

/**
 * returns true if the url is absolute (begins with http)
 *
 * @param string $url
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
 * @return array<int|string, mixed>
 */
function get_user_favorites(): array
{
    global $user;

    if (is_a_guest()) {
        return [];
    }

    $query = '
SELECT
    image_id,
    1 as fake_value
  FROM ' . FAVORITES_TABLE . '
  WHERE user_id = ' . $user['id'] . '
';

    return query2array($query, 'image_id', 'fake_value');
}
