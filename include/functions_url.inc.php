<?php

declare(strict_types=1);

use Piwigo\Url\UrlService;

// Thin delegates — logic lives in UrlService. Kept for include/ callers.

function get_root_url(): string { return UrlService::getRootUrl(); }

function get_absolute_root_url(bool $with_scheme = true): string { return UrlService::getAbsoluteRootUrl($with_scheme); }

/**
 * @param string|string[] $url
 * @return string|string[]
 */
function embellish_url(string|array $url): string|array { return UrlService::embellishUrl($url); }

function url_is_remote(string $url): bool { return UrlService::urlIsRemote($url); }

/** @param array<mixed> $params */
function add_url_params(string $url, array $params, string $arg_separator = '&amp;'): string
{
    return UrlService::get()->addUrlParams($url, $params, $arg_separator);
}

/** @param array<mixed> $params */
function make_index_url(array $params = []): string { return UrlService::get()->makeIndexUrl($params); }

/**
 * @param array<mixed> $redefined
 * @param string[] $removed
 */
function duplicate_index_url(array $redefined = [], array $removed = []): string
{
    return UrlService::get()->duplicateIndexUrl($redefined, $removed);
}

/**
 * @param array<mixed> $redefined
 * @param string[] $removed
 * @return array<mixed>
 */
function params_for_duplication(array $redefined, array $removed): array
{
    return UrlService::get()->paramsForDuplication($redefined, $removed);
}

/**
 * @param array<mixed> $redefined
 * @param string[] $removed
 */
function duplicate_picture_url(array $redefined = [], array $removed = []): string
{
    return UrlService::get()->duplicatePictureUrl($redefined, $removed);
}

/** @param array<mixed> $params */
function make_picture_url(array $params): string { return UrlService::get()->makePictureUrl($params); }

/** @param array<mixed> $params */
function add_well_known_params_in_url(string $url, array $params): string
{
    return UrlService::get()->addWellKnownParamsInUrl($url, $params);
}

/** @param array<mixed> $params */
function make_section_in_url(array $params): string { return UrlService::get()->makeSectionInUrl($params); }

/**
 * @param string[] $tokens
 * @return array<mixed>
 */
function parse_section_url(array $tokens, int &$next_token): array
{
    return UrlService::get()->parseSectionUrl($tokens, $next_token);
}

/**
 * @param string[] $tokens
 * @return array<mixed>
 */
function parse_well_known_params_url(array $tokens, int &$i): array
{
    return UrlService::get()->parseWellKnownParamsUrl($tokens, $i);
}

function get_action_url(int|string $id, string $what_part, bool $download): string
{
    return UrlService::get()->getActionUrl($id, $what_part, $download);
}

/** @param array<string,mixed> $element_info */
function get_element_url(array $element_info): string { return UrlService::get()->getElementUrl($element_info); }

function set_make_full_url(): void { UrlService::get()->setMakeFullUrl(); }

function unset_make_full_url(): void { UrlService::get()->unsetMakeFullUrl(); }

function get_gallery_home_url(): string { return UrlService::get()->getGalleryHomeUrl(); }

/** @param string[] $rejects */
function get_query_string_diff(array $rejects = [], bool $escape = true): string
{
    return UrlService::get()->getQueryStringDiff($rejects, $escape);
}

/** @return array<int,true> */
function get_user_favorites(): array { return UrlService::get()->getUserFavorites(); }
