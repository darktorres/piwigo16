<?php

declare(strict_types=1);

use Piwigo\Image\SrcImage;
use Piwigo\Menu\BlockManager;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @package functions\html
 */

/** @param array<mixed> $cat_informations */
function get_cat_display_name(array $cat_informations, ?string $url = ''): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->getCatDisplayName($cat_informations, $url);
}

function get_cat_display_name_cache(
    string $uppercats,
    ?string $url = '',
    bool $single_link = false,
    ?string $link_class = null,
    ?string $auth_key = null
): string {
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->getCatDisplayNameCache($uppercats, $url, $single_link, $link_class, $auth_key);
}

function get_cat_display_name_from_id(int|string $cat_id, ?string $url = ''): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->getCatDisplayNameFromId($cat_id, $url);
}

function render_comment_content(string $content): string|null
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->renderCommentContent($content);
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function name_compare(array $a, array $b): int
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->nameCompare($a, $b);
}

/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function tag_alpha_compare(array $a, array $b): int
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->tagAlphaCompare($a, $b);
}

function access_denied(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->accessDenied();
}

function page_forbidden(string $msg, ?string $alternate_url = null): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->pageForbidden($msg, $alternate_url);
}

function bad_request(string $msg, ?string $alternate_url = null): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->badRequest($msg, $alternate_url);
}

function page_not_found(?string $msg, ?string $alternate_url = null): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->pageNotFound($msg, $alternate_url);
}

function fatal_error(string $msg, ?string $title = null, bool $show_trace = true): never
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->fatalError($msg, $title, $show_trace);
}

function get_tags_content_title(): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->getTagsContentTitle();
}

function get_combined_categories_content_title(): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->getCombinedCategoriesContentTitle();
}

function set_status_header(int $code, string $text = ''): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->setStatusHeader($code, $text);
}

function render_category_literal_description(?string $desc): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->renderCategoryLiteralDescription($desc);
}

/** @param BlockManager[] $menu_ref_arr */
function register_default_menubar_blocks(array $menu_ref_arr): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->registerDefaultMenubarBlocks($menu_ref_arr);
}

/** @param array<string, mixed> $info */
function render_element_name(array $info): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->renderElementName($info);
}

/** @param array<string, mixed> $info */
function render_element_description(array $info, string $param = ''): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->renderElementDescription($info, $param);
}

/** @param array<string, mixed> $info */
function get_thumbnail_title(array $info, string $title, string $comment = ''): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->getThumbnailTitle($info, $title, $comment);
}

function get_src_image_url_protection_handler(string $url, \Piwigo\Image\SrcImage $src_image): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->getSrcImageUrlProtectionHandler($url, $src_image);
}

/** @param array<string, mixed> $infos */
function get_element_url_protection_handler(string $url, array $infos): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->getElementUrlProtectionHandler($url, $infos);
}

function flush_page_messages(): void
{
    \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->flushPageMessages();
}

function pwg_nl2br(string $string): string
{
    return \Piwigo\Core\ServiceLocator::get(\Piwigo\Html\HtmlService::class)->pwgNl2br($string);
}
