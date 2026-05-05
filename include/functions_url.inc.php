<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Called before Kernel::boot() in common.inc.php upgrade redirect —
 * must have its own implementation. UrlService::getRootUrl() is canonical.
 */
function get_root_url(): string
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

/**
 * Called from i.php before Kernel::boot() — must have its own implementation.
 * UrlService::getAbsoluteRootUrl() is canonical.
 */
function get_absolute_root_url(bool $with_scheme = true): string
{
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO']) {
        $_SERVER['HTTPS'] = 'on';
    }

    $url = '';
    if ($with_scheme) {
        $isHttps = false;
        if (isset($_SERVER['HTTPS']) && is_scalar($_SERVER['HTTPS']) &&
          ((strtolower((string) $_SERVER['HTTPS']) === 'on') || ((int) $_SERVER['HTTPS'] === 1))) {
            $isHttps = true;
            $url    .= 'https://';
        } else {
            $url .= 'http://';
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $url .= is_scalar($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string) $_SERVER['HTTP_X_FORWARDED_HOST'] : '';
        } else {
            $url    .= is_scalar($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
            $urlPort = null;
            $cfgPort = \Piwigo\Config\Config::urlPort();
            if ('none' === $cfgPort) {
                // do nothing
            } elseif ('auto' === $cfgPort) {
                $serverPort = is_scalar($_SERVER['SERVER_PORT'] ?? null) ? (int) $_SERVER['SERVER_PORT'] : 80;
                if ((!$isHttps && $serverPort !== 80) || ($isHttps && $serverPort !== 443)) {
                    $urlPort = ':' . $serverPort;
                }
            } else {
                $urlPort = ':' . $cfgPort;
            }
            if (!empty($urlPort) && strrchr($url, ':') !== $urlPort) {
                $url .= $urlPort;
            }
        }
    }
    $url .= cookie_path();
    return $url;
}

/** @param array<mixed> $params */
function add_url_params(string $url, array $params, string $arg_separator = '&amp;'): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->addUrlParams($url, $params, $arg_separator);
}

/** @param array<mixed> $params */
function make_index_url(array $params = []): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->makeIndexUrl($params);
}

/**
 * @param array<mixed> $redefined
 * @param string[]     $removed
 */
function duplicate_index_url(array $redefined = [], array $removed = []): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->duplicateIndexUrl($redefined, $removed);
}

/**
 * @param array<mixed> $redefined
 * @param string[]     $removed
 * @return array<mixed>
 */
function params_for_duplication(array $redefined, array $removed): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->paramsForDuplication($redefined, $removed);
}

/**
 * @param array<mixed> $redefined
 * @param string[]     $removed
 */
function duplicate_picture_url(array $redefined = [], array $removed = []): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->duplicatePictureUrl($redefined, $removed);
}

/** @param array<mixed> $params */
function make_picture_url(array $params): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->makePictureUrl($params);
}

/** @param array<mixed> $params */
function add_well_known_params_in_url(string $url, array $params): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->addWellKnownParamsInUrl($url, $params);
}

/** @param array<mixed> $params */
function make_section_in_url(array $params): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->makeSectionInUrl($params);
}

/**
 * @param string[] $tokens
 * @return array<mixed>
 */
function parse_section_url(array $tokens, int &$next_token): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->parseSectionUrl($tokens, $next_token);
}

/**
 * @param string[] $tokens
 * @return array<mixed>
 */
function parse_well_known_params_url(array $tokens, int &$i): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->parseWellKnownParamsUrl($tokens, $i);
}

function get_action_url(int|string $id, string $what_part, bool $download): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->getActionUrl($id, $what_part, $download);
}

/** @param array<string,mixed> $element_info */
function get_element_url(array $element_info): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->getElementUrl($element_info);
}

function set_make_full_url(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->setMakeFullUrl();
}

function unset_make_full_url(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->unsetMakeFullUrl();
}

/**
 * Called from i.php before Kernel::boot() — must have its own implementation.
 * UrlService::embellishUrl() is canonical.
 *
 * @param string|string[] $url
 * @return string|string[]
 */
function embellish_url(string|array $url): string|array
{
    if (is_array($url)) {
        return array_map(static fn (string $u): string => is_string($r = embellish_url($u)) ? $r : $u, $url);
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

function get_gallery_home_url(): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->getGalleryHomeUrl();
}

/**
 * @param string[] $rejects
 */
function get_query_string_diff(array $rejects = [], bool $escape = true): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->getQueryStringDiff($rejects, $escape);
}

function url_is_remote(string $url): bool
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->urlIsRemote($url);
}

/** @return array<int,true> */
function get_user_favorites(): array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->getUserFavorites();
}
