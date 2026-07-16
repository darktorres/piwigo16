<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

use Piwigo\Url\UrlService;

// P23 batch 8c: relocated unchanged from the deleted include/functions_url.inc.php
// -- every function here is a pure 1-line delegate to Piwigo\Url\UrlService
// (already real, already typed), too widely called (get_root_url() alone:
// ~150 call sites) to retarget every caller onto the class directly, same
// "relocate ubiquitous utilities as unchanged free functions" two-track
// precedent as P23 batch 7's Piwigo\PluginConfig\functions.php. 3 of the
// original file's 20 functions (params_for_duplication(),
// add_well_known_params_in_url(), make_section_in_url()) had zero real
// callers anywhere and were dropped, not relocated.
//
// Every declaration is guarded with function_exists() for the same reason
// as PluginConfig/functions.php: composer's autoload.files loads this file
// once at process start, but a class_exists()/interface_exists() probe for
// a plausible-but-nonexistent FQCN under this namespace (e.g.
// tests/Arch/StructuralTest.php's "every Piwigo\ class ... has #[\Override]"
// test, which computes Piwigo\Url\functions from this file's own basename
// while walking every .php file under src/Piwigo/) makes composer's PSR-4
// resolver try to autoload Piwigo\Url\functions, resolve it to this exact
// file via the PSR-4 basename match, and `include` it a second time. The
// guard makes the second pass a safe no-op instead of a fatal.

/**
 * returns a prefix for each url link on displayed page
 * and return an empty string for current path
 */
if (! function_exists('get_root_url')) {
    function get_root_url(): string
    {
        return new UrlService()
            ->getRootUrl();
    }
}

/**
 * returns the absolute url to the root of PWG
 * @param bool $with_scheme if false - does not add http://toto.com
 */
if (! function_exists('get_absolute_root_url')) {
    function get_absolute_root_url($with_scheme = true): string
    {
        return new UrlService()
            ->getAbsoluteRootUrl($with_scheme);
    }
}

/**
 * adds one or more _GET style parameters to an url
 * example: add_url_params('/x', array('a'=>'b')) returns /x?a=b
 * add_url_params('/x?cat_id=10', array('a'=>'b')) returns /x?cat_id=10&amp;a=b
 * @param string $url
 * @param array<int|string, mixed> $params
 * @return string
 */
if (! function_exists('add_url_params')) {
    function add_url_params($url, $params, string $arg_separator = '&amp;')
    {
        return new UrlService()
            ->addUrlParams($url, $params, $arg_separator);
    }
}

/**
 * build an index URL for a specific section
 *
 * @param array<string, mixed> $params
 */
if (! function_exists('make_index_url')) {
    function make_index_url(array $params = []): string
    {
        return new UrlService()
            ->makeIndexUrl($params);
    }
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
if (! function_exists('duplicate_index_url')) {
    function duplicate_index_url($redefined = [], $removed = []): string
    {
        return new UrlService()
            ->duplicateIndexUrl($redefined, $removed);
    }
}

/**
 * create a picture URL with current page parameters, but with redefinitions
 * and removes. See duplicate_index_url.
 *
 * @param array<string, mixed> $redefined keys
 * @param array<int, string> $removed keys
 */
if (! function_exists('duplicate_picture_url')) {
    function duplicate_picture_url($redefined = [], $removed = []): string
    {
        return new UrlService()
            ->duplicatePictureUrl($redefined, $removed);
    }
}

/**
 * create a picture URL on a specific section for a specific picture
 *
 * @param array<string, mixed> $params
 */
if (! function_exists('make_picture_url')) {
    function make_picture_url(array $params): string
    {
        return new UrlService()
            ->makePictureUrl($params);
    }
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
if (! function_exists('parse_section_url')) {
    function parse_section_url(array $tokens, &$next_token): array
    {
        return new UrlService()
            ->parseSectionUrl($tokens, $next_token);
    }
}

/**
 * the reverse of add_well_known_params_in_url
 * parses start, flat and chronology from url tokens
 * @param string[] $tokens
 * @return list<string>[]|string[]|true[]
 */
if (! function_exists('parse_well_known_params_url')) {
    function parse_well_known_params_url(array $tokens, int &$i): array
    {
        return new UrlService()
            ->parseWellKnownParamsUrl($tokens, $i);
    }
}

/**
 * @param int|string $id image id
 * @param string $what_part one of 'e' (element), 'r' (representative)
 */
if (! function_exists('get_action_url')) {
    function get_action_url($id, $what_part, bool $download): string
    {
        return new UrlService()
            ->getActionUrl($id, $what_part, $download);
    }
}

/**
 * @param array<string, mixed> $element_info containing element information from db;
 * at least 'id', 'path' should be present
 */
if (! function_exists('get_element_url')) {
    function get_element_url(array $element_info): mixed
    {
        return new UrlService()
            ->getElementUrl($element_info);
    }
}

/**
 * Indicate to build url with full path
 */
if (! function_exists('set_make_full_url')) {
    function set_make_full_url(): void
    {
        new UrlService()
            ->setMakeFullUrl();
    }
}

/**
 * Restore old parameter to build url with full path
 */
if (! function_exists('unset_make_full_url')) {
    function unset_make_full_url(): void
    {
        new UrlService()
            ->unsetMakeFullUrl();
    }
}

/**
 * Embellish the url argument
 *
 * @param string $url
 */
if (! function_exists('embellish_url')) {
    function embellish_url($url): string
    {
        return new UrlService()
            ->embellishUrl($url);
    }
}

/**
 * Returns the 'home page' of this gallery
 */
if (! function_exists('get_gallery_home_url')) {
    function get_gallery_home_url(): mixed
    {
        return new UrlService()
            ->getGalleryHomeUrl();
    }
}

/**
 * returns $_SERVER['QUERY_STRING'] whithout keys given in parameters
 *
 * @param string[] $rejects
 * @param bool $escape escape *&* to *&amp;*
 */
if (! function_exists('get_query_string_diff')) {
    function get_query_string_diff($rejects = [], $escape = true): string
    {
        return new UrlService()
            ->getQueryStringDiff($rejects, $escape);
    }
}

/**
 * returns true if the url is absolute (begins with http)
 *
 * @param string $url
 */
if (! function_exists('url_is_remote')) {
    function url_is_remote($url): bool
    {
        return new UrlService()
            ->urlIsRemote($url);
    }
}

/**
 * List favorite image_ids of the current user.
 * @since 13
 * @return array<int|string, mixed>
 */
if (! function_exists('get_user_favorites')) {
    function get_user_favorites(): array
    {
        return new UrlService()
            ->getUserFavorites();
    }
}
