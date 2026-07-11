<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Html\HtmlService;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\BlockManager;

/**
 * Generates breadcrumb from categories list.
 * Categories string returned contains categories as given in the input
 * array $cat_informations. $cat_informations array must be an array
 * of array( id=>?, name=>?, permalink=>?). If url input parameter is null,
 * returns only the categories name without links.
 *
 * @param array<int, array<string, mixed>> $cat_informations
 * @param string|null $url
 */
function get_cat_display_name($cat_informations, $url = ''): string
{
    return new HtmlService()
        ->getCatDisplayName($cat_informations, $url);
}

/**
 * Generates breadcrumb from categories list using a cache.
 * @see get_cat_display_name()
 *
 * @param string $uppercats
 * @param string|null $url
 * @param bool $single_link
 * @param string|null $link_class
 * @param string|null $auth_key
 */
function get_cat_display_name_cache(
    $uppercats,
    $url = '',
    $single_link = false,
    $link_class = null,
    $auth_key = null
): string {
    return new HtmlService()
        ->getCatDisplayNameCache($uppercats, $url, $single_link, $link_class, $auth_key);
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
    return new HtmlService()
        ->getCatDisplayNameFromId($cat_id, $url);
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
 */
function render_comment_content($content): string|null
{
    return new HtmlService()
        ->renderCommentContent($content);
}

/**
 * Callback used for sorting by name.
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function name_compare(array $a, array $b): int
{
    return new HtmlService()
        ->nameCompare($a, $b);
}

/**
 * Callback used for sorting by name (slug) with cache.
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function tag_alpha_compare(array $a, array $b): int
{
    return new HtmlService()
        ->tagAlphaCompare($a, $b);
}

/**
 * Exits the current script.
 */
function access_denied(): never
{
    new HtmlService()
        ->accessDenied();
}

/**
 * Exits the current script with 403 code.
 * @todo nice display if $template loaded
 *
 * @param string $msg
 * @param string|null $alternate_url redirect to this url
 */
function page_forbidden($msg, $alternate_url = null): never
{
    new HtmlService()
        ->pageForbidden($msg, $alternate_url);
}

/**
 * Exits the current script with 400 code.
 * @todo nice display if $template loaded
 *
 * @param string $msg
 * @param string|null $alternate_url redirect to this url
 */
function bad_request($msg, $alternate_url = null): never
{
    new HtmlService()
        ->badRequest($msg, $alternate_url);
}

/**
 * Exits the current script with 404 code.
 * @todo nice display if $template loaded
 *
 * @param string|null $msg null is treated the same as '' below (string
 *   concatenation); comments.php passes null when comments are disabled
 * @param string|null $alternate_url redirect to this url
 */
function page_not_found($msg, $alternate_url = null): never
{
    new HtmlService()
        ->pageNotFound($msg, $alternate_url);
}

/**
 * Exits the current script with 500 code.
 * @todo nice display if $template loaded
 *
 * @param string $msg
 * @param string|null $title
 * @param bool $show_trace
 */
function fatal_error($msg, $title = null, $show_trace = true): never
{
    new HtmlService()
        ->fatalError($msg, $title, $show_trace);
}

/**
 * Returns the breadcrumb to be displayed above thumbnails on tag page.
 */
function get_tags_content_title(): string
{
    return new HtmlService()
        ->getTagsContentTitle();
}

/**
 * Returns the breadcrumb to be displayed above thumbnails on combined categories page.
 */
function get_combined_categories_content_title(): string
{
    return new HtmlService()
        ->getCombinedCategoriesContentTitle();
}

/**
 * Sets the http status header (200,401,...)
 * @param int $code
 * @param string $text for exotic http codes
 */
function set_status_header($code, $text = ''): void
{
    new HtmlService()
        ->setStatusHeader($code, $text);
}

/**
 * Returns the category comment for rendering in html textual mode (subcatify)
 * This method is called by a trigger_notify()
 */
function render_category_literal_description(?string $desc): string
{
    return new HtmlService()
        ->renderCategoryLiteralDescription($desc);
}

/**
 * Add known menubar blocks.
 * This method is called by a trigger_change()
 *
 * @param BlockManager[] $menu_ref_arr
 */
function register_default_menubar_blocks(array $menu_ref_arr): void
{
    new HtmlService()
        ->registerDefaultMenubarBlocks($menu_ref_arr);
}

/**
 * Returns display name for an element.
 * Returns 'name' if exists of name from 'file'.
 *
 * @param array<string, mixed> $info at least file or name
 */
function render_element_name(array $info): string
{
    return new HtmlService()
        ->renderElementName($info);
}

/**
 * Returns display description for an element.
 *
 * @param array<string, mixed> $info at least comment
 * @param string $param used to identify the trigger
 */
function render_element_description(array $info, $param = ''): string
{
    return new HtmlService()
        ->renderElementDescription($info, $param);
}

/**
 * Add info to the title of the thumbnail based on photo properties.
 *
 * @param array<string, mixed> $info hit, rating_score, nb_comments
 * @param string $title
 * @param string $comment
 */
function get_thumbnail_title(array $info, $title, $comment = ''): string
{
    return new HtmlService()
        ->getThumbnailTitle($info, $title, $comment);
}

/**
 * Event handler to protect src image urls.
 *
 * @param string $url
 * @param SrcImage $src_image
 */
function get_src_image_url_protection_handler($url, $src_image): string
{
    return new HtmlService()
        ->getSrcImageUrlProtectionHandler($url, $src_image);
}

/**
 * Event handler to protect element urls.
 *
 * @param string $url
 * @param array<string, mixed> $infos id, path
 * @return string
 */
function get_element_url_protection_handler($url, array $infos)
{
    return new HtmlService()
        ->getElementUrlProtectionHandler($url, $infos);
}

/**
 * Sends to the template all messages stored in $page and in the session.
 */
function flush_page_messages(): void
{
    new HtmlService()
        ->flushPageMessages();
}

/**
 * pwg_nl2br is useful for PHP 5.2 which doesn't accept more than 1
 * parameter on nl2br() (and anyway the second parameter of nl2br does not
 * match what Piwigo gives.
 *
 * @param array<int|string, mixed>|null|int|float|false|string $string
 * @return array<int|string, mixed>|null|int|float|false|string
 */
function pwg_nl2br($string): array|null|int|float|false|string
{
    return new HtmlService()
        ->pwgNl2br($string);
}
