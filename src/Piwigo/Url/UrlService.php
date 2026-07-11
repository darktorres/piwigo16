<?php

declare(strict_types=1);

namespace Piwigo\Url;

use Piwigo\Config\Config;
use Piwigo\Db\Tables;

/**
 * URL building/parsing for the gallery's own routes (index/picture/action
 * sections, permalinks, chronology/start/flat params).
 *
 * Injects nothing -- cross-domain calls (get_cat_info(), find_tags(),
 * str2url(), is_a_guest(), l10n(), page_not_found(), etc.) stay as plain
 * global-function calls to modules not yet migrated in P17 (Category/Tag/
 * Auth/Html are P17-20 territory beyond this phase's own Html addition),
 * matching every one of these free functions' own already-established
 * "1-line delegate to the new class" migration shape once their module
 * lands. Same "injects nothing" shape the reference implementation's own
 * mature UrlService settled on.
 */
final class UrlService
{
    /**
     * Returns a prefix for each url link on displayed page and returns an
     * empty string for current path.
     */
    public function getRootUrl(): string
    {
        /** @var array<string, mixed> $page */
        global $page;
        $root_path = $page['root_path'] ?? null;
        if (! is_string($root_path) || $root_path === '') {
            $root_url = PHPWG_ROOT_PATH;
            if (str_starts_with($root_url, './')) {
                return substr($root_url, 2);
            }
        } else {
            $root_url = $root_path;
        }

        return $root_url;
    }

    /**
     * Returns the absolute url to the root of PWG.
     *
     * @param bool $withScheme if false - does not add http://toto.com
     */
    public function getAbsoluteRootUrl(bool $withScheme = true): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        // Support X-Forwarded-Proto header for HTTPS detection in PHP
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) and $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $_SERVER['HTTPS'] = 'on';
        }

        $url = '';
        if ($withScheme) {
            $is_https = false;
            $https_value = $_SERVER['HTTPS'] ?? null;
            if (is_scalar($https_value) &&
              ((strtolower((string) $https_value) === 'on') or ((string) $https_value === '1'))) {
                $is_https = true;
                $url .= 'https://';
            } else {
                $url .= 'http://';
            }
            // A configured gallery_url is a canonical, admin-set base URL --
            // its host is trusted outright and never derived from a
            // client-controlled header. [SEC-29]
            $configured_host = $this->configuredHost();

            if ($configured_host !== null) {
                $url .= $configured_host;
            } else {
                $forwarded_host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null;
                if (is_string($forwarded_host)) {
                    $url .= $this->trustedHost($forwarded_host);
                } else {
                    $http_host = $_SERVER['HTTP_HOST'] ?? null;
                    $url .= $this->trustedHost(is_string($http_host) ? $http_host : '');

                    $url_port = null;

                    if ($conf['url_port'] === 'none') {
                        // do nothing
                    } elseif ($conf['url_port'] === 'auto') {
                        $server_port_raw = $_SERVER['SERVER_PORT'] ?? null;
                        $server_port = is_numeric($server_port_raw) ? (int) $server_port_raw : null;
                        if ((! $is_https && $server_port !== 80) || ($is_https && $server_port !== 443)) {
                            $url_port = ':' . ((is_string($server_port_raw) || is_int($server_port_raw)) ? $server_port_raw : '');
                        }
                    } else {
                        // we have a custom port
                        $url_port = ':' . (is_scalar($conf['url_port']) ? $conf['url_port'] : '');
                    }

                    if ($url_port !== null and strrchr($url, ':') !== $url_port) {
                        $url .= $url_port;
                    }
                }
            }
        }
        $url .= cookie_path();

        return $url;
    }

    /**
     * Returns Config::galleryUrl()'s host[:port], or null when unconfigured
     * (auto-detect mode).
     */
    private function configuredHost(): ?string
    {
        $gallery_url = Config::galleryUrl();
        if ($gallery_url === null) {
            return null;
        }

        $host = parse_url($gallery_url, \PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($gallery_url, \PHP_URL_PORT);

        return is_int($port) ? $host . ':' . $port : $host;
    }

    /**
     * [SEC-29] Host-header poisoning guard: when Config::allowedHosts() is
     * non-empty, an unrecognized candidate host is never embedded into an
     * outbound URL -- falls back to the first configured host instead.
     * Empty allow-list means "not configured", preserving the historical
     * auto-detect (trust the header) behavior.
     */
    private function trustedHost(string $candidate): string
    {
        $allowed = Config::allowedHosts();
        if ($allowed === [] || in_array($candidate, $allowed, true)) {
            return $candidate;
        }

        return $allowed[0];
    }

    /**
     * Adds one or more _GET style parameters to an url.
     * example: addUrlParams('/x', ['a'=>'b']) returns /x?a=b
     * addUrlParams('/x?cat_id=10', ['a'=>'b']) returns /x?cat_id=10&amp;a=b
     *
     * @param array<int|string, mixed> $params
     */
    public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string
    {
        if ($params !== []) {
            if (defined('IN_WS') and $argSeparator === '&amp;') {
                $argSeparator = '&';
            }

            $is_first = true;
            foreach ($params as $param => $val) {
                if ($is_first) {
                    $is_first = false;
                    $url .= (! str_contains($url, '?')) ? '?' : $argSeparator;
                } else {
                    $url .= $argSeparator;
                }
                $url .= $param;
                if (isset($val)) {
                    $url .= '=' . (is_scalar($val) ? $val : '');
                }
            }
        }

        return $url;
    }

    /**
     * Build an index URL for a specific section.
     *
     * @param array<string, mixed> $params
     */
    public function makeIndexUrl(array $params = []): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        $url = $this->getRootUrl() . 'index';
        if ((bool) $conf['php_extension_in_urls']) {
            $url .= '.php';
        }
        if ((bool) $conf['question_mark_in_urls']) {
            $url .= '?';
        }

        $url_before_params = $url;

        $url .= $this->makeSectionInUrl($params);
        $url = $this->addWellKnownParamsInUrl($url, $params);

        if ($url === $url_before_params) {
            $url = $this->getAbsoluteRootUrl($this->urlIsRemote($url));
        }

        return $url;
    }

    /**
     * Build an index URL with current page parameters, but with
     * redefinitions and removes.
     *
     * duplicateIndexUrl([
     *   'category' => ['id'=>12, 'name'=>'toto'],
     * ], ['start']) will create an index URL on the current section
     * (categories), but on a redefined category and without the start URL
     * parameter.
     *
     * @param array<string, mixed> $redefined keys
     * @param array<int, string> $removed keys
     */
    public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
    {
        return $this->makeIndexUrl(
            $this->paramsForDuplication($redefined, $removed),
        );
    }

    /**
     * Returns $page global array with key redefined and key removed.
     *
     * @param array<string, mixed> $redefined keys
     * @param array<int, string> $removed keys
     * @return array<string, mixed>
     */
    public function paramsForDuplication(array $redefined, array $removed): array
    {
        /** @var array<string, mixed> $page */
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
     * Create a picture URL with current page parameters, but with
     * redefinitions and removes. See duplicateIndexUrl().
     *
     * @param array<string, mixed> $redefined keys
     * @param array<int, string> $removed keys
     */
    public function duplicatePictureUrl(array $redefined = [], array $removed = []): string
    {
        return $this->makePictureUrl(
            $this->paramsForDuplication($redefined, $removed),
        );
    }

    /**
     * Create a picture URL on a specific section for a specific picture.
     *
     * @param array<string, mixed> $params
     */
    public function makePictureUrl(array $params): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $url = $this->getRootUrl() . 'picture';
        if ((bool) $conf['php_extension_in_urls']) {
            $url .= '.php';
        }
        if ((bool) $conf['question_mark_in_urls']) {
            $url .= '?';
        }
        $url .= '/';
        $image_id = $params['image_id'] ?? null;
        $picture_url_style = $conf['picture_url_style'] ?? null;
        switch ($picture_url_style) {
            case 'id-file':
                $url .= is_scalar($image_id) ? $image_id : '';
                if (isset($params['image_file']) and is_string($params['image_file'])) {
                    $url .= '-' . str2url(get_filename_wo_extension($params['image_file']));
                }

                break;
            case 'file':
                if (isset($params['image_file']) and is_string($params['image_file'])) {
                    $fname_wo_ext = get_filename_wo_extension($params['image_file']);
                    if (ord($fname_wo_ext) > ord('9') or ! (bool) preg_match('/^\d+(-|$)/', $fname_wo_ext)) {
                        $url .= $fname_wo_ext;

                        break;
                    }
                }
                // no break
            default:
                $url .= is_scalar($image_id) ? $image_id : '';
        }
        if (! isset($params['category'])) { // make urls shorter ...
            unset($params['flat']);
        }
        $url .= $this->makeSectionInUrl($params);
        $url = $this->addWellKnownParamsInUrl($url, $params);

        return $url;
    }

    /**
     * Adds to the url the chronology and start parameters.
     *
     * @param array<string, mixed> $params
     */
    public function addWellKnownParamsInUrl(string $url, array $params): string
    {
        if (isset($params['chronology_field'])) {
            $chronology_field = $params['chronology_field'];
            $url .= '/' . (is_scalar($chronology_field) ? $chronology_field : '');

            $chronology_style = $params['chronology_style'] ?? null;
            $url .= '-' . (is_scalar($chronology_style) ? $chronology_style : '');

            if (isset($params['chronology_view'])) {
                $chronology_view = $params['chronology_view'];
                $url .= '-' . (is_scalar($chronology_view) ? $chronology_view : '');
            }
            if (isset($params['chronology_date']) && $params['chronology_date'] !== []) {
                $chronology_date = $params['chronology_date'];
                $url .= '-' . implode('-', is_array($chronology_date) ? array_filter($chronology_date, is_scalar(...)) : []);
            }
        }

        if (isset($params['flat'])) {
            $url .= '/flat';
        }

        if (isset($params['start']) and $params['start'] > 0) {
            $start = $params['start'];
            $url .= '/start-' . (is_scalar($start) ? $start : '');
        }

        return $url;
    }

    /**
     * Return the section token of an index or picture URL.
     *
     * Depending on section, other parameters are required (see method body
     * for details).
     *
     * @param array<string, mixed> $params
     */
    public function makeSectionInUrl(array $params): string
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        $section_string = '';
        $section_raw = $params['section'] ?? null;
        $section = is_string($section_raw) ? $section_raw : null;
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
                    $category_info = $params['category'];
                    if (! is_array($category_info)) {
                        $category_info = [];
                    }

                    isset($category_info['name']) or trigger_error(
                        'makeSectionInUrl category name not set',
                        E_USER_WARNING,
                    );

                    array_key_exists('permalink', $category_info) or trigger_error(
                        'makeSectionInUrl category permalink not set',
                        E_USER_WARNING,
                    );

                    $section_string .= '/category/';
                    if (! isset($category_info['permalink']) || $category_info['permalink'] === '') {
                        $category_id = $category_info['id'] ?? null;
                        $section_string .= is_scalar($category_id) ? $category_id : '';
                        if ($conf['category_url_style'] === 'id-name') {
                            $category_name = $category_info['name'] ?? null;
                            $section_string .= '-' . str2url(is_string($category_name) ? $category_name : '');
                        }
                    } else {
                        $category_permalink = $category_info['permalink'];
                        $section_string .= is_scalar($category_permalink) ? $category_permalink : '';
                    }

                    if (isset($params['combined_categories']) and is_array($params['combined_categories'])) {
                        foreach ($params['combined_categories'] as $category) {
                            $section_string .= '/';

                            if (! is_array($category)) {
                                $category = [];
                            }

                            if (! isset($category['permalink']) || $category['permalink'] === '') {
                                $combined_id = $category['id'] ?? null;
                                $section_string .= is_scalar($combined_id) ? $combined_id : '';
                                if ($conf['category_url_style'] === 'id-name') {
                                    $combined_name = $category['name'] ?? null;
                                    $section_string .= '-' . str2url(is_string($combined_name) ? $combined_name : '');
                                }
                            } else {
                                $combined_permalink = $category['permalink'];
                                $section_string .= is_scalar($combined_permalink) ? $combined_permalink : '';
                            }
                        }
                    }
                }

                break;

            case 'tags':

                $section_string .= '/tags';

                $tags_param = $params['tags'] ?? [];
                $tag_url_style = $conf['tag_url_style'] ?? null;
                foreach ((is_array($tags_param) ? $tags_param : []) as $tag) {
                    if (! is_array($tag)) {
                        $tag = [];
                    }
                    $tag_id = $tag['id'] ?? null;
                    $tag_url_name = $tag['url_name'] ?? null;
                    switch ($tag_url_style) {
                        case 'id':
                            $section_string .= '/' . (is_scalar($tag_id) ? $tag_id : '');

                            break;
                        case 'tag':
                            if (isset($tag_url_name) && is_scalar($tag_url_name)) {
                                $section_string .= '/' . $tag_url_name;

                                break;
                            }
                            // no break
                        default:
                            $section_string .= '/' . (is_scalar($tag_id) ? $tag_id : '');
                            if (isset($tag_url_name) && is_scalar($tag_url_name)) {
                                $section_string .= '-' . $tag_url_name;
                            }
                    }
                }

                break;

            case 'search':

                $search_param = $params['search'] ?? null;
                $section_string .= '/search/' . (is_scalar($search_param) ? $search_param : '');

                break;

            case 'list':

                $list_param = $params['list'] ?? [];
                $section_string .= '/list/' . implode(',', is_array($list_param) ? array_filter($list_param, is_scalar(...)) : []);

                break;

            case 'none':

                break;

            default:

                $section_string .= '/' . $section;

        }

        return $section_string;
    }

    /**
     * The reverse of makeSectionInUrl().
     * Returns the 'section' (categories/tags/...) and the data associated
     * with it.
     *
     * Depending on section, other parameters are returned (category/tags/
     * list/...).
     *
     * @param string[] $tokens of url tokens to parse
     * @param int $nextToken the index in the array of url tokens; in/out
     * @return array<string, mixed>
     */
    public function parseSectionUrl(array $tokens, &$nextToken): array
    {
        $page = [];
        if (isset($tokens[$nextToken]) and str_starts_with($tokens[$nextToken], 'categor')) {
            $page['section'] = 'categories';
            $nextToken++;

            $loop_counter = 0;

            /** @var int|numeric-string|null $category */
            $category = null;
            /** @var array<int, int|numeric-string> $combined_category_ids */
            $combined_category_ids = [];
            /** @var array{cat_url_name?: string, cat_permalink?: string} $hit_by */
            $hit_by = [];

            while (isset($tokens[$nextToken])) {
                if ($loop_counter++ > count($tokens) + 10) {
                    die('infinite loop?');
                }

                if (
                    str_starts_with($tokens[$nextToken], 'created-')
                    or str_starts_with($tokens[$nextToken], 'posted-')
                    or str_starts_with($tokens[$nextToken], 'start-')
                    or str_starts_with($tokens[$nextToken], 'startcat-')
                    or $tokens[$nextToken] === 'flat'
                ) {
                    break;
                }

                if ((bool) preg_match('/^(\d+)(?:-(.+))?$/', $tokens[$nextToken], $matches)) {
                    if (isset($matches[2])) {
                        $hit_by['cat_url_name'] = $matches[2];
                    }

                    if ($category === null) {
                        $category = $matches[1];
                    } else {
                        $combined_category_ids[] = $matches[1];
                    }
                    $nextToken++;
                } else { // try a permalink
                    $maybe_permalinks = [];
                    $current_token = $nextToken;
                    while (isset($tokens[$current_token])
                        and ! str_starts_with($tokens[$current_token], 'created-')
                        and ! str_starts_with($tokens[$current_token], 'posted-')
                        and ! str_starts_with($tokens[$current_token], 'start-')
                        and ! str_starts_with($tokens[$current_token], 'startcat-')
                        and $tokens[$current_token] !== 'flat') {
                        if ($maybe_permalinks === []) {
                            $maybe_permalinks[] = $tokens[$current_token];
                        } else {
                            $maybe_permalinks[] =
                                $maybe_permalinks[count($maybe_permalinks) - 1]
                                . '/' . $tokens[$current_token];
                        }
                        $current_token++;
                    }

                    if ((bool) count($maybe_permalinks)) {
                        $cat_id = get_cat_id_from_permalinks($maybe_permalinks, $perma_index);
                        if (isset($cat_id)) {
                            $nextToken += $perma_index + 1;

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

            if ($hit_by !== []) {
                $page['hit_by'] = $hit_by;
            }

            if ($category !== null) {
                $result = get_cat_info((int) $category);
                if ($result === null || $result === []) {
                    page_not_found(l10n('Requested album does not exist'));
                }
                $page['category'] = $result;
            }

            if ($combined_category_ids !== []) {
                $combined_categories = [];

                foreach ($combined_category_ids as $cat_id) {
                    $result = get_cat_info((int) $cat_id);
                    if ($result === null || $result === []) {
                        page_not_found(l10n('Requested album does not exist'));
                    }

                    $combined_categories[] = $result;
                }

                $page['combined_categories'] = $combined_categories;
            }
        } elseif (@$tokens[$nextToken] === 'tags') {
            /** @var array<string, mixed> $conf */
            global $conf;

            $page['section'] = 'tags';
            $page['tags'] = [];

            $nextToken++;
            $i = $nextToken;

            $requested_tag_ids = [];
            $requested_tag_url_names = [];

            while (isset($tokens[$i])) {
                if (str_starts_with($tokens[$i], 'created-')
                     or str_starts_with($tokens[$i], 'posted-')
                     or str_starts_with($tokens[$i], 'start-')) {
                    break;
                }

                if ($conf['tag_url_style'] !== 'tag' and (bool) preg_match('/^(\d+)(?:-(.*)|)$/', $tokens[$i], $matches)) {
                    $requested_tag_ids[] = $matches[1];
                } else {
                    $requested_tag_url_names[] = $tokens[$i];
                }
                $i++;
            }
            $nextToken = $i;

            if ($requested_tag_ids === [] && $requested_tag_url_names === []) {
                bad_request('at least one tag required');
            }

            $page['tags'] = find_tags($requested_tag_ids, $requested_tag_url_names);
            if ($page['tags'] === []) {
                page_not_found(l10n('Requested tag does not exist'), $this->getRootUrl() . 'tags.php');
            }
        } elseif (@$tokens[$nextToken] === 'favorites') {
            $page['section'] = 'favorites';
            $nextToken++;
        } elseif (@$tokens[$nextToken] === 'most_visited') {
            $page['section'] = 'most_visited';
            $nextToken++;
        } elseif (@$tokens[$nextToken] === 'best_rated') {
            $page['section'] = 'best_rated';
            $nextToken++;
        } elseif (@$tokens[$nextToken] === 'recent_pics') {
            $page['section'] = 'recent_pics';
            $nextToken++;
        } elseif (@$tokens[$nextToken] === 'recent_cats') {
            $page['section'] = 'recent_cats';
            $nextToken++;
        } elseif (@$tokens[$nextToken] === 'search') {
            $page['section'] = 'search';
            $nextToken++;

            preg_match('/^(psk-\d{8}-[a-zA-Z0-9]{10})$/', @$tokens[$nextToken], $matches);
            if (! isset($matches[1])) {
                preg_match('/(\d+)/', @$tokens[$nextToken], $matches);
                if (! isset($matches[1])) {
                    bad_request('search identifier is missing');
                }
            }
            $page['search'] = $matches[1];
            $nextToken++;
        } elseif (@$tokens[$nextToken] === 'list') {
            $page['section'] = 'list';
            $nextToken++;

            $page['list'] = [];

            // No pictures
            if (! isset($tokens[$nextToken]) || $tokens[$nextToken] === '') {
                // Add dummy element list
                $page['list'][] = -1;
            }
            // With pictures list
            else {
                if (! (bool) preg_match('/^\d+(,\d+)*$/', $tokens[$nextToken])) {
                    bad_request('wrong format on list GET parameter');
                }
                foreach (explode(',', $tokens[$nextToken]) as $image_id) {
                    $page['list'][] = $image_id;
                }
            }
            $nextToken++;
        }

        return $page;
    }

    /**
     * The reverse of addWellKnownParamsInUrl().
     * Parses start, flat and chronology from url tokens.
     *
     * @param string[] $tokens
     * @return list<string>[]|string[]|true[]
     */
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array
    {
        $page = [];
        while (isset($tokens[$i])) {
            if ($tokens[$i] === 'flat') {
                // indicate a special list of images
                $page['flat'] = true;
            } elseif (str_starts_with($tokens[$i], 'created-') or str_starts_with($tokens[$i], 'posted-')) {
                $chronology_tokens = explode('-', $tokens[$i]);

                $page['chronology_field'] = $chronology_tokens[0];

                array_shift($chronology_tokens);
                $page['chronology_style'] = $chronology_tokens[0];

                if (! in_array($page['chronology_style'], ['monthly', 'weekly'], true)) {
                    fatal_error('bad chronology field (style)');
                }

                array_shift($chronology_tokens);
                if (count($chronology_tokens) > 0) {
                    if ($chronology_tokens[0] === 'list' or
                        $chronology_tokens[0] === 'calendar') {
                        $page['chronology_view'] = $chronology_tokens[0];
                        array_shift($chronology_tokens);
                    }
                    $page['chronology_date'] = $chronology_tokens;

                    foreach ($page['chronology_date'] as $date_token) {
                        // each date part must be an integer (number of the year, number of the month, number of the week or number of the day)
                        if (! (bool) preg_match('/^(\d+|any)$/', $date_token)) {
                            fatal_error('bad chronology field (date)');
                        }
                    }
                }
            } elseif ((bool) preg_match('/^start-(\d+)/', $tokens[$i], $matches)) {
                $page['start'] = $matches[1];
            } elseif ((bool) preg_match('/^startcat-(\d+)/', $tokens[$i], $matches)) {
                $page['startcat'] = $matches[1];
            }
            $i++;
        }

        return $page;
    }

    /**
     * @param int|string $id image id
     * @param string $whatPart one of 'e' (element), 'r' (representative)
     */
    public function getActionUrl($id, $whatPart, bool $download): string
    {
        $params = [
            'id' => $id,
            'part' => $whatPart,
        ];
        if ($download) {
            $params['download'] = null;
        }

        return $this->addUrlParams($this->getRootUrl() . 'action.php', $params);
    }

    /**
     * @param array<string, mixed> $elementInfo containing element information
     * from db; at least 'id', 'path' should be present
     */
    public function getElementUrl(array $elementInfo): mixed
    {
        $url = $elementInfo['path'];
        if (is_string($url) && ! $this->urlIsRemote($url)) {
            $url = $this->embellishUrl($this->getRootUrl() . $url);
        }

        return $url;
    }

    /**
     * Indicate to build url with full path.
     */
    public function setMakeFullUrl(): void
    {
        /** @var array<string, mixed> $page */
        global $page;

        if (! is_array($page['save_root_path'] ?? null)) {
            $save_root_path = [];
            if (isset($page['root_path'])) {
                $save_root_path['path'] = $page['root_path'];
            }
            $save_root_path['count'] = 1;
            $page['save_root_path'] = $save_root_path;
            $page['root_path'] = $this->getAbsoluteRootUrl();
        } else {
            $save_root_path = $page['save_root_path'];
            $count = $save_root_path['count'] ?? 0;
            $count = is_scalar($count) ? (int) $count : 0;
            $save_root_path['count'] = $count + 1;
            $page['save_root_path'] = $save_root_path;
        }
    }

    /**
     * Restore old parameter to build url with full path.
     */
    public function unsetMakeFullUrl(): void
    {
        /** @var array<string, mixed> $page */
        global $page;

        $save_root_path = $page['save_root_path'] ?? null;
        if (is_array($save_root_path)) {
            $count = $save_root_path['count'] ?? null;
            if (is_int($count) && $count === 1) {
                if (isset($save_root_path['path'])) {
                    $page['root_path'] = $save_root_path['path'];
                } else {
                    unset($page['root_path']);
                }
                unset($page['save_root_path']);
            } else {
                $count_int = is_scalar($count) ? (int) $count : 0;
                $save_root_path['count'] = $count_int - 1;
                $page['save_root_path'] = $save_root_path;
            }
        }
    }

    /**
     * Embellish the url argument.
     */
    public function embellishUrl(string $url): string
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
     * Returns the 'home page' of this gallery.
     */
    public function getGalleryHomeUrl(): mixed
    {
        /** @var array<string, mixed> $conf */
        global $conf;
        $gallery_url = $conf['gallery_url'] ?? null;
        if (is_string($gallery_url) && $gallery_url !== '') {
            if ($this->urlIsRemote($gallery_url) or $gallery_url[0] === '/') {
                return $gallery_url;
            }

            return $this->getRootUrl() . $gallery_url;
        }

        return $this->makeIndexUrl();
    }

    /**
     * Returns $_SERVER['QUERY_STRING'] whithout keys given in parameters.
     *
     * @param string[] $rejects
     * @param bool $escape escape *&* to *&amp;*
     */
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
    {
        $query_string = $_SERVER['QUERY_STRING'] ?? null;
        if (! is_string($query_string) || $query_string === '') {
            return '';
        }

        parse_str($query_string, $vars);

        $vars = array_diff_key($vars, array_flip($rejects));

        return '?' . http_build_query($vars, '', $escape ? '&amp;' : '&');
    }

    /**
     * Returns true if the url is absolute (begins with http).
     */
    public function urlIsRemote(string $url): bool
    {
        return str_starts_with($url, 'http://')
            or str_starts_with($url, 'https://');
    }

    /**
     * List favorite image_ids of the current user.
     * @since 13
     *
     * @return array<int|string, mixed>
     */
    public function getUserFavorites(): array
    {
        /** @var array<string, mixed> $user */
        global $user;

        if (is_a_guest()) {
            return [];
        }

        $user_id = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;

        $query = '
SELECT
    image_id,
    1 as fake_value
  FROM ' . Tables::favorites() . '
  WHERE user_id = ' . $user_id . '
';

        return query2array($query, 'image_id', 'fake_value');
    }
}
