<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Url\UrlService;

/**
 * returns a prefix for each url link on displayed page
 * and return an empty string for current path
 */
function get_root_url(): string
{
    return new UrlService()
        ->getRootUrl();
}

/**
 * returns the absolute url to the root of PWG
 * @param bool $with_scheme if false - does not add http://toto.com
 */
function get_absolute_root_url($with_scheme = true): string
{
    return new UrlService()
        ->getAbsoluteRootUrl($with_scheme);
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
    return new UrlService()
        ->addUrlParams($url, $params, $arg_separator);
}

/**
 * build an index URL for a specific section
 *
 * @param array<string, mixed> $params
 */
function make_index_url(array $params = []): string
{
    return new UrlService()
        ->makeIndexUrl($params);
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
    return new UrlService()
        ->duplicateIndexUrl($redefined, $removed);
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
    return new UrlService()
        ->paramsForDuplication($redefined, $removed);
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
    return new UrlService()
        ->duplicatePictureUrl($redefined, $removed);
}

/**
 * create a picture URL on a specific section for a specific picture
 *
 * @param array<string, mixed> $params
 */
function make_picture_url(array $params): string
{
    return new UrlService()
        ->makePictureUrl($params);
}

/**
 *adds to the url the chronology and start parameters
 *
 * @param array<string, mixed> $params
 */
function add_well_known_params_in_url(string $url, array $params): string
{
    return new UrlService()
        ->addWellKnownParamsInUrl($url, $params);
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
    return new UrlService()
        ->makeSectionInUrl($params);
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
    return new UrlService()
        ->parseSectionUrl($tokens, $next_token);
}

/**
 * the reverse of add_well_known_params_in_url
 * parses start, flat and chronology from url tokens
 * @param string[] $tokens
 * @return list<string>[]|string[]|true[]
 */
function parse_well_known_params_url(array $tokens, int &$i): array
{
    return new UrlService()
        ->parseWellKnownParamsUrl($tokens, $i);
}

/**
 * @param int|string $id image id
 * @param string $what_part one of 'e' (element), 'r' (representative)
 */
function get_action_url($id, $what_part, bool $download): string
{
    return new UrlService()
        ->getActionUrl($id, $what_part, $download);
}

/**
 * @param array<string, mixed> $element_info containing element information from db;
 * at least 'id', 'path' should be present
 */
function get_element_url(array $element_info): mixed
{
    return new UrlService()
        ->getElementUrl($element_info);
}

/**
 * Indicate to build url with full path
 */
function set_make_full_url(): void
{
    new UrlService()
        ->setMakeFullUrl();
}

/**
 * Restore old parameter to build url with full path
 */
function unset_make_full_url(): void
{
    new UrlService()
        ->unsetMakeFullUrl();
}

/**
 * Embellish the url argument
 *
 * @param string $url
 */
function embellish_url($url): string
{
    return new UrlService()
        ->embellishUrl($url);
}

/**
 * Returns the 'home page' of this gallery
 */
function get_gallery_home_url(): mixed
{
    return new UrlService()
        ->getGalleryHomeUrl();
}

/**
 * returns $_SERVER['QUERY_STRING'] whithout keys given in parameters
 *
 * @param string[] $rejects
 * @param bool $escape escape *&* to *&amp;*
 */
function get_query_string_diff($rejects = [], $escape = true): string
{
    return new UrlService()
        ->getQueryStringDiff($rejects, $escape);
}

/**
 * returns true if the url is absolute (begins with http)
 *
 * @param string $url
 */
function url_is_remote($url): bool
{
    return new UrlService()
        ->urlIsRemote($url);
}

/**
 * List favorite image_ids of the current user.
 * @since 13
 * @return array<int|string, mixed>
 */
function get_user_favorites(): array
{
    return new UrlService()
        ->getUserFavorites();
}
