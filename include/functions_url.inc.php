<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

function get_root_url(): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->getRootUrl();
}

function get_absolute_root_url(bool $with_scheme = true): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->getAbsoluteRootUrl($with_scheme);
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
 * @param string|string[] $url
 * @return string|string[]
 */
function embellish_url(string|array $url): string|array
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlService::class)->embellishUrl($url);
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
