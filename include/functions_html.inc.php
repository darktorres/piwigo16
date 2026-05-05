<?php

declare(strict_types=1);

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

/**
 * Called before Kernel::boot() on DB-connect errors — must have its own
 * implementation. HtmlService::fatalError() is the canonical copy.
 */
function fatal_error(string $msg, ?string $title = null, bool $show_trace = true): never
{
    if (empty($title)) {
        $title = function_exists('l10n') ? l10n('Piwigo encountered a non recoverable error') : 'Piwigo encountered a non recoverable error';
    }

    $btraceMsg = '';
    if ($show_trace and function_exists('debug_backtrace')) {
        $bt = debug_backtrace();
        for ($i = 1; $i < count($bt); $i++) {
            $class      = isset($bt[$i]['class']) ? ($bt[$i]['class'] . '::') : '';
            $btraceMsg .= "#$i\t" . $class . $bt[$i]['function'] . ' ' . ($bt[$i]['file'] ?? '') . '(' . ($bt[$i]['line'] ?? '') . ")\n";
        }
        $btraceMsg = trim($btraceMsg);
        $msg .= "\n";
    }

    $display = "<meta http-equiv='Content-Type' content='text/html; charset=utf-8'>
<h1>$title</h1>
<pre style='font-size:larger;background:white;color:red;padding:1em;margin:0;clear:both;display:block;width:auto;height:auto;overflow:auto'>
<b>$msg</b>
$btraceMsg
</pre>\n";

    if (!headers_sent()) {
        if (function_exists('set_status_header') && \Piwigo\Core\ServiceLocator::has(\Piwigo\Html\HtmlService::class)) {
            set_status_header(500);
        } else {
            header('HTTP/1.0 500 Server error', true, 500);
        }
    }
    echo $display . str_repeat(' ', 300);

    if (function_exists('ini_set')) {
        ini_set('display_errors', false);
    }
    error_reporting(E_ALL);
    throw new \RuntimeException(strip_tags($msg) . $btraceMsg);
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
