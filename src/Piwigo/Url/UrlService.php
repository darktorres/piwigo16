<?php

declare(strict_types=1);

namespace Piwigo\Url;

use Piwigo\Config\Config;
use Piwigo\Users\CurrentUser;

final class UrlService
{
    public function getRootUrl(): string
    {
        $page     = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $rootPath = $page['root_path'] ?? null;
        if (is_string($rootPath) && $rootPath !== '') {
            return $rootPath;
        }
        $rootUrl = PHPWG_ROOT_PATH;
        if (str_starts_with($rootUrl, './')) {
            return substr($rootUrl, 2);
        }
        return $rootUrl;
    }

    public function getAbsoluteRootUrl(bool $withScheme = true): string
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) and 'https' == $_SERVER['HTTP_X_FORWARDED_PROTO']) {
            $_SERVER['HTTPS'] = 'on';
        }

        $url = '';
        if ($withScheme) {
            $isHttps = false;
            if (isset($_SERVER['HTTPS']) && is_scalar($_SERVER['HTTPS']) &&
              ((strtolower((string) $_SERVER['HTTPS']) == 'on') or ($_SERVER['HTTPS'] == 1))) {
                $isHttps = true;
                $url .= 'https://';
            } else {
                $url .= 'http://';
            }
            if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
                $url .= is_scalar($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string) $_SERVER['HTTP_X_FORWARDED_HOST'] : '';
            } else {
                $url .= is_scalar($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';

                $urlPort = null;

                if ('none' == Config::urlPort()) {
                    // do nothing
                } elseif ('auto' == Config::urlPort()) {
                    if ((!$isHttps && $_SERVER['SERVER_PORT'] != 80) || ($isHttps && $_SERVER['SERVER_PORT'] != 443)) {
                        $urlPort = ':' . (is_scalar($_SERVER['SERVER_PORT']) ? (string) $_SERVER['SERVER_PORT'] : '');
                    }
                } else {
                    $urlPort = ':' . Config::urlPort();
                }

                if (!empty($urlPort) and strrchr($url, ':') != $urlPort) {
                    $url .= $urlPort;
                }
            }
        }
        $url .= cookie_path();
        return $url;
    }

    /** @param array<mixed> $params */
    public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string
    {
        if (!empty($params)) {
            if (defined('IN_WS') and '&amp;' === $argSeparator) {
                $argSeparator = '&';
            }

            $isFirst = true;
            foreach ($params as $param => $val) {
                if ($isFirst) {
                    $isFirst = false;
                    $url .= (!str_contains((string) $url, '?')) ? '?' : $argSeparator;
                } else {
                    $url .= $argSeparator;
                }
                $url .= $param;
                if (isset($val)) {
                    $url .= '=' . (is_scalar($val) ? (string) $val : '');
                }
            }
        }
        return $url;
    }

    /** @param array<mixed> $params */
    public function makeIndexUrl(array $params = []): string
    {
        $url = $this->getRootUrl() . 'index';
        if (Config::phpExtensionInUrls()) {
            $url .= '.php';
        }
        if (Config::questionMarkInUrls()) {
            $url .= '?';
        }

        $urlBeforeParams = $url;
        $url .= $this->makeSectionInUrl($params);
        $url  = $this->addWellKnownParamsInUrl($url, $params);

        if ($url == $urlBeforeParams) {
            $url = $this->getAbsoluteRootUrl($this->urlIsRemote($url));
        }

        return $url;
    }

    /**
     * @param array<mixed> $redefined
     * @param string[]     $removed
     */
    public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
    {
        return $this->makeIndexUrl($this->paramsForDuplication($redefined, $removed));
    }

    /**
     * @param array<mixed> $redefined
     * @param string[]     $removed
     * @return array<mixed>
     */
    public function paramsForDuplication(array $redefined, array $removed): array
    {
        $params = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

        foreach ($removed as $paramKey) {
            unset($params[$paramKey]);
        }

        foreach ($redefined as $redefinedParam => $redefinedValue) {
            $params[$redefinedParam] = $redefinedValue;
        }

        return $params;
    }

    /**
     * @param array<mixed> $redefined
     * @param string[]     $removed
     */
    public function duplicatePictureUrl(array $redefined = [], array $removed = []): string
    {
        return $this->makePictureUrl($this->paramsForDuplication($redefined, $removed));
    }

    /** @param array<mixed> $params */
    public function makePictureUrl(array $params): string
    {
        $url = $this->getRootUrl() . 'picture';
        if (Config::phpExtensionInUrls()) {
            $url .= '.php';
        }
        if (Config::questionMarkInUrls()) {
            $url .= '?';
        }
        $url .= '/';
        switch (Config::pictureUrlStyle()) {
            case 'id-file':
                $url .= is_scalar($params['image_id']) ? (string) $params['image_id'] : '';
                if (isset($params['image_file'])) {
                    $url .= '-' . str2url(get_filename_wo_extension(is_scalar($params['image_file']) ? (string) $params['image_file'] : ''));
                }
                break;
            case 'file':
                if (isset($params['image_file'])) {
                    $fnameWoExt = get_filename_wo_extension(is_scalar($params['image_file']) ? (string) $params['image_file'] : '');
                    if (ord($fnameWoExt[0]) > ord('9') or !preg_match('/^\d+(-|$)/', $fnameWoExt)) {
                        $url .= $fnameWoExt;
                        break;
                    }
                }
                // no break
            default:
                $url .= is_scalar($params['image_id']) ? (string) $params['image_id'] : '';
        }
        if (!isset($params['category'])) {
            unset($params['flat']);
        }
        $url .= $this->makeSectionInUrl($params);
        $url  = $this->addWellKnownParamsInUrl($url, $params);
        return $url;
    }

    /** @param array<mixed> $params */
    public function addWellKnownParamsInUrl(string $url, array $params): string
    {
        if (isset($params['chronology_field'])) {
            $url .= '/' . (is_scalar($params['chronology_field']) ? (string) $params['chronology_field'] : '');
            $url .= '-' . (is_scalar($params['chronology_style']) ? (string) $params['chronology_style'] : '');
            if (isset($params['chronology_view'])) {
                $url .= '-' . (is_scalar($params['chronology_view']) ? (string) $params['chronology_view'] : '');
            }
            if (!empty($params['chronology_date'])) {
                $url .= '-' . implode('-', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', is_array($params['chronology_date']) ? $params['chronology_date'] : []));
            }
        }

        if (isset($params['flat'])) {
            $url .= '/flat';
        }

        if (isset($params['start']) and $params['start'] > 0) {
            $url .= '/start-' . (is_scalar($params['start']) ? (string) $params['start'] : '');
        }
        return $url;
    }

    /** @param array<mixed> $params */
    public function makeSectionInUrl(array $params): string
    {
        $sectionString = '';
        $section       = $params['section'] ?? null;
        if (!isset($section)) {
            $sectionOf = [
                'category' => 'categories',
                'tags'     => 'tags',
                'list'     => 'list',
                'search'   => 'search',
            ];

            foreach ($sectionOf as $param => $s) {
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
                        $sectionString .= '/categories';
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

                        $sectionString .= '/category/';
                        if (empty($cat['permalink'])) {
                            $sectionString .= is_scalar($cat['id']) ? (string) $cat['id'] : '';
                            if (Config::categoryUrlStyle() == 'id-name') {
                                $sectionString .= '-' . str2url(is_scalar($cat['name']) ? (string) $cat['name'] : '');
                            }
                        } else {
                            $sectionString .= is_scalar($cat['permalink']) ? (string) $cat['permalink'] : '';
                        }

                        if (isset($params['combined_categories'])) {
                            foreach ((array) $params['combined_categories'] as $category) {
                                if (!is_array($category)) {
                                    continue;
                                }
                                $sectionString .= '/';

                                if (empty($category['permalink'])) {
                                    $sectionString .= is_scalar($category['id']) ? (string) $category['id'] : '';
                                    if (Config::categoryUrlStyle() == 'id-name') {
                                        $sectionString .= '-' . str2url(is_scalar($category['name']) ? (string) $category['name'] : '');
                                    }
                                } else {
                                    $sectionString .= is_scalar($category['permalink']) ? (string) $category['permalink'] : '';
                                }
                            }
                        }
                    }

                    break;
                }
            case 'tags':
                {
                    $sectionString .= '/tags';

                    foreach ((array) $params['tags'] as $tag) {
                        if (!is_array($tag)) {
                            continue;
                        }
                        switch (Config::tagUrlStyle()) {
                            case 'id':
                                $sectionString .= '/' . (is_scalar($tag['id']) ? (string) $tag['id'] : '');
                                break;
                            case 'tag':
                                if (isset($tag['url_name'])) {
                                    $sectionString .= '/' . (is_scalar($tag['url_name']) ? (string) $tag['url_name'] : '');
                                    break;
                                }
                                // no break
                            default:
                                $sectionString .= '/' . (is_scalar($tag['id']) ? (string) $tag['id'] : '');
                                if (isset($tag['url_name'])) {
                                    $sectionString .= '-' . (is_scalar($tag['url_name']) ? (string) $tag['url_name'] : '');
                                }
                        }
                    }

                    break;
                }
            case 'search':
                {
                    $sectionString .= '/search/' . (is_scalar($params['search']) ? (string) $params['search'] : '');
                    break;
                }
            case 'list':
                {
                    $sectionString .= '/list/' . implode(',', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', is_array($params['list']) ? $params['list'] : []));
                    break;
                }
            case 'none':
                {
                    break;
                }
            default:
                {
                    $sectionString .= '/' . (is_scalar($section) ? (string) $section : '');
                }
        }

        return $sectionString;
    }

    /**
     * @param string[] $tokens
     * @return array<mixed>
     */
    public function parseSectionUrl(array $tokens, int &$nextToken): array
    {
        $page = ['hit_by' => [], 'combined_categories' => null];
        if (isset($tokens[$nextToken]) and str_starts_with($tokens[$nextToken], 'categor')) {
            $page['section'] = 'categories';
            $nextToken++;

            $i           = $nextToken;
            $loopCounter = 0;

            while (isset($tokens[$nextToken])) {
                if ($loopCounter++ > count($tokens) + 10) {
                    throw new \LogicException('infinite loop?');
                }

                if (
                    str_starts_with($tokens[$nextToken], 'created-')
                    or str_starts_with($tokens[$nextToken], 'posted-')
                    or str_starts_with($tokens[$nextToken], 'start-')
                    or str_starts_with($tokens[$nextToken], 'startcat-')
                    or 'flat' == $tokens[$nextToken]
                ) {
                    break;
                }

                if (preg_match('/^(\d+)(?:-(.+))?$/', $tokens[$nextToken], $matches)) {
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
                    $nextToken++;
                } else {
                    $maybePermalinks = [];
                    $currentToken    = $nextToken;
                    while (isset($tokens[$currentToken])
                        and !str_starts_with($tokens[$currentToken], 'created-')
                        and !str_starts_with($tokens[$currentToken], 'posted-')
                        and !str_starts_with((string) $tokens[$nextToken], 'start-')
                        and !str_starts_with((string) $tokens[$nextToken], 'startcat-')
                        and $tokens[$currentToken] != 'flat') {
                        if (empty($maybePermalinks)) {
                            $maybePermalinks[] = $tokens[$currentToken];
                        } else {
                            $maybePermalinks[] =
                                $maybePermalinks[count($maybePermalinks) - 1]
                                . '/' . $tokens[$currentToken];
                        }
                        $currentToken++;
                    }

                    if (count($maybePermalinks)) {
                        $permaIndex = 0;
                        $catId      = get_cat_id_from_permalinks($maybePermalinks, $permaIndex);
                        if (isset($catId)) {
                            $nextToken += $permaIndex + 1;

                            if (!isset($page['category'])) {
                                $page['category']                = $catId;
                                $page['hit_by']['cat_permalink'] = $maybePermalinks[$permaIndex];
                            } else {
                                if (!is_array($page['combined_categories'])) {
                                    $page['combined_categories'] = [];
                                }
                                $page['combined_categories'][] = $catId;
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
                $combinedCategories = [];

                foreach ($page['combined_categories'] as $catId) {
                    $result = get_cat_info($catId);
                    if (empty($result)) {
                        page_not_found(l10n('Requested album does not exist'));
                    }

                    $combinedCategories[] = $result;
                }

                $page['combined_categories'] = $combinedCategories;
            }
        } elseif ('tags' == ($tokens[$nextToken] ?? null)) {
            $page['section'] = 'tags';
            $page['tags']    = [];

            $nextToken++;
            $i = $nextToken;

            $requestedTagIds      = [];
            $requestedTagUrlNames = [];

            while (isset($tokens[$i])) {
                if (str_starts_with($tokens[$i], 'created-')
                     or str_starts_with($tokens[$i], 'posted-')
                     or str_starts_with($tokens[$i], 'start-')) {
                    break;
                }

                if (Config::tagUrlStyle() != 'tag' and preg_match('/^(\d+)(?:-(.*)|)$/', $tokens[$i], $matches)) {
                    $requestedTagIds[] = $matches[1];
                } else {
                    $requestedTagUrlNames[] = $tokens[$i];
                }
                $i++;
            }
            $nextToken = $i;

            if (empty($requestedTagIds) && empty($requestedTagUrlNames)) {
                bad_request('at least one tag required');
            }

            $page['tags'] = find_tags($requestedTagIds, $requestedTagUrlNames);
            if (empty($page['tags'])) {
                page_not_found(l10n('Requested tag does not exist'), get_root_url() . 'tags.php');
            }
        } elseif ('favorites' == ($tokens[$nextToken] ?? null)) {
            $page['section'] = 'favorites';
            $nextToken++;
        } elseif ('most_visited' == ($tokens[$nextToken] ?? null)) {
            $page['section'] = 'most_visited';
            $nextToken++;
        } elseif ('best_rated' == ($tokens[$nextToken] ?? null)) {
            $page['section'] = 'best_rated';
            $nextToken++;
        } elseif ('recent_pics' == ($tokens[$nextToken] ?? null)) {
            $page['section'] = 'recent_pics';
            $nextToken++;
        } elseif ('recent_cats' == ($tokens[$nextToken] ?? null)) {
            $page['section'] = 'recent_cats';
            $nextToken++;
        } elseif ('search' == ($tokens[$nextToken] ?? null)) {
            $page['section'] = 'search';
            $nextToken++;

            preg_match('/^(psk-\d{8}-[a-zA-Z0-9]{10})$/', (string) ($tokens[$nextToken] ?? ''), $matches);
            if (!isset($matches[1])) {
                preg_match('/(\d+)/', (string) ($tokens[$nextToken] ?? ''), $matches);
                if (!isset($matches[1])) {
                    bad_request('search identifier is missing');
                    return $page;
                }
            }
            $page['search'] = $matches[1];
            $nextToken++;
        } elseif ('list' == ($tokens[$nextToken] ?? null)) {
            $page['section'] = 'list';
            $nextToken++;

            $page['list'] = [];

            if (empty($tokens[$nextToken])) {
                $page['list'][] = -1;
            } else {
                if (!preg_match('/^\d+(,\d+)*$/', (string) $tokens[$nextToken])) {
                    bad_request('wrong format on list GET parameter');
                }
                foreach (explode(',', (string) $tokens[$nextToken]) as $imageId) {
                    $page['list'][] = $imageId;
                }
            }
            $nextToken++;
        }
        return $page;
    }

    /**
     * @param string[] $tokens
     * @return array<mixed>
     */
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array
    {
        $page = [];
        while (isset($tokens[$i])) {
            if ('flat' == $tokens[$i]) {
                $page['flat'] = true;
            } elseif (str_starts_with($tokens[$i], 'created-') or str_starts_with($tokens[$i], 'posted-')) {
                $chronologyTokens = explode('-', $tokens[$i]);

                $page['chronology_field'] = $chronologyTokens[0];

                array_shift($chronologyTokens);
                $page['chronology_style'] = $chronologyTokens[0];

                if (!in_array($page['chronology_style'], ['monthly', 'weekly'])) {
                    fatal_error('bad chronology field (style)');
                }

                array_shift($chronologyTokens);
                if (count($chronologyTokens) > 0) {
                    if ('list' == $chronologyTokens[0] or
                        'calendar' == $chronologyTokens[0]) {
                        $page['chronology_view'] = $chronologyTokens[0];
                        array_shift($chronologyTokens);
                    }
                    $page['chronology_date'] = $chronologyTokens;

                    foreach ($page['chronology_date'] as $dateToken) {
                        if (!preg_match('/^(\d+|any)$/', $dateToken)) {
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

    public function getActionUrl(int|string $id, string $whatPart, bool $download): string
    {
        $params = [
            'id'   => (int) $id,
            'part' => $whatPart,
        ];
        if ($download) {
            $params['download'] = null;
        }

        return $this->addUrlParams($this->getRootUrl() . 'action.php', $params);
    }

    /** @param array<string,mixed> $elementInfo */
    public function getElementUrl(array $elementInfo): string
    {
        $url = is_scalar($elementInfo['path']) ? (string) $elementInfo['path'] : '';
        if (!$this->urlIsRemote($url)) {
            $result = $this->embellishUrl($this->getRootUrl() . $url);
            return is_string($result) ? $result : '';
        }
        return $url;
    }

    public function setMakeFullUrl(): void
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
            $newSave['count']       = 1;
            $page['save_root_path'] = $newSave;
            $page['root_path']      = $this->getAbsoluteRootUrl();
        } else {
            $count             = is_numeric($save['count'] ?? null) ? (int) $save['count'] : 0;
            $save['count']     = $count + 1;
            $page['save_root_path'] = $save;
        }
    }

    public function unsetMakeFullUrl(): void
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
            $save['count']          = $count - 1;
            $page['save_root_path'] = $save;
        }
    }

    /**
     * @param string|string[] $url
     * @return string|string[]
     */
    public function embellishUrl(string|array $url): string|array
    {
        if (is_array($url)) {
            return array_map(fn (string $u): string => is_string($r = $this->embellishUrl($u)) ? $r : $u, $url);
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

    public function getGalleryHomeUrl(): string
    {
        if (!empty(Config::galleryUrl())) {
            if ($this->urlIsRemote(Config::galleryUrl()) or Config::galleryUrl()[0] == '/') {
                return Config::galleryUrl();
            }
            return $this->getRootUrl() . Config::galleryUrl();
        } else {
            return $this->makeIndexUrl();
        }
    }

    /**
     * @param string[] $rejects
     */
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
    {
        if (empty($_SERVER['QUERY_STRING'])) {
            return '';
        }

        parse_str(is_scalar($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '', $vars);

        $vars = array_diff_key($vars, array_flip($rejects));

        return '?' . http_build_query($vars, '', $escape ? '&amp;' : '&');
    }

    public function urlIsRemote(string $url): bool
    {
        if (str_starts_with($url, 'http://')
          or str_starts_with($url, 'https://')) {
            return true;
        }
        return false;
    }

    /** @return array<int,true> */
    public function getUserFavorites(): array
    {
        if (is_a_guest()) {
            return [];
        }

        $query = '
SELECT
    image_id,
    1 as fake_value
  FROM ' . FAVORITES_TABLE . '
  WHERE user_id = ' . CurrentUser::get()->id . '
';

        $raw    = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'fake_value', 'image_id');
        $result = [];
        foreach ($raw as $imageId => $val) {
            $result[(int) $imageId] = true;
        }
        return $result;
    }
}
