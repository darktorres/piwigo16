<?php

declare(strict_types=1);

namespace Piwigo\Html;

use Piwigo\Cache\RequestCache;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\RegisteredBlock;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;

final class HtmlService
{
    /** @param array<mixed> $catInformations */
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        $output = '';
        $isFirst = true;

        foreach ($catInformations as $cat) {
            if (!is_array($cat)) {
                trigger_error('get_cat_display_name wrong type for category ', E_USER_WARNING);
                continue;
            }

            $cat['name'] = trigger_change(
                'render_category_name',
                is_scalar($cat['name']) ? (string) $cat['name'] : '',
                'get_cat_display_name'
            );

            if ($isFirst) {
                $isFirst = false;
            } else {
                $output .= Config::levelSeparator();
            }

            if (!isset($url)) {
                $output .= $cat['name'];
            } elseif ($url == '') {
                $output .= '<a href="' . make_index_url(['category' => $cat]) . '">';
                $output .= $cat['name'] . '</a>';
            } else {
                $output .= '<a href="' . PHPWG_ROOT_PATH . $url . (is_scalar($cat['id']) ? (string) $cat['id'] : '') . '">';
                $output .= $cat['name'] . '</a>';
            }
        }
        return $output;
    }

    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null
    ): string {
        $addUrlParamsArr = [];
        if (isset($authKey)) {
            $addUrlParamsArr['auth'] = $authKey;
        }

        $catNamesRaw = RequestCache::remember('cat_names', 'all', static function (): array {
            $query = '
SELECT id, name, permalink
  FROM ' . CATEGORIES_TABLE . '
;';
            return array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'id');
        });
        /** @var array<int|string, array<string,mixed>> $catNames */
        $catNames = is_array($catNamesRaw) ? $catNamesRaw : [];

        $output = '';
        if ($singleLink) {
            $uppercatsArray = explode(',', $uppercats);
            $singleUrl      = add_url_params(get_root_url() . $url . array_pop($uppercatsArray), $addUrlParamsArr);
            $output .= '<a href="' . $singleUrl . '"';
            if (isset($linkClass)) {
                $output .= ' class="' . $linkClass . '"';
            }
            $output .= '>';
        }
        $isFirst = true;
        foreach (explode(',', $uppercats) as $categoryId) {
            $cat = $catNames[$categoryId] ?? null;
            if (!is_array($cat)) {
                continue;
            }

            $cat['name'] = trigger_change(
                'render_category_name',
                is_scalar($cat['name']) ? (string) $cat['name'] : '',
                'get_cat_display_name_cache'
            );

            if ($isFirst) {
                $isFirst = false;
            } else {
                $output .= '<span>' . Config::levelSeparator() . '</span>';
            }

            $catName = $cat['name'];
            if (!isset($url) or $singleLink) {
                $output .= $catName;
            } elseif ($url == '') {
                $output .= '
<a href="' . add_url_params(make_index_url(['category' => $cat]), $addUrlParamsArr) . '">' . $catName . '</a>';
            } else {
                $output .= '
<a href="' . PHPWG_ROOT_PATH . $url . $categoryId . '">' . $catName . '</a>';
            }
        }

        if ($singleLink) {
            $output .= '</a>';
        }

        return $output;
    }

    public function getCatDisplayNameFromId(int|string $catId, ?string $url = ''): string
    {
        $catInfo    = get_cat_info($catId);
        $upperNames = $catInfo['upper_names'] ?? [];
        return $this->getCatDisplayName(is_array($upperNames) ? $upperNames : [], $url);
    }

    public function renderCommentContent(string $content): string|null
    {
        $content    = htmlspecialchars($content);
        $pattern    = '/(https?:\/\/\S*)/';
        $replacement = '<a href="$1" rel="nofollow">$1</a>';
        $content    = preg_replace($pattern, $replacement, $content);
        $content    = nl2br((string) $content);

        $pattern    = '/\b_(\S*)_\b/';
        $replacement = '<span style="text-decoration:underline;">$1</span>';
        $content    = preg_replace($pattern, $replacement, $content);

        $pattern    = '/\b\*(\S*)\*\b/';
        $replacement = '<span style="font-weight:bold;">$1</span>';
        $content    = preg_replace($pattern, $replacement, (string) $content);

        $pattern    = "/\/(\S*)\/(\s)/";
        $replacement = '<span style="font-style:italic;">$1$2</span>';
        $content    = preg_replace($pattern, $replacement, (string) $content);

        return $content;
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function nameCompare(array $a, array $b): int
    {
        return strcmp(strtolower(is_scalar($a['name']) ? (string) $a['name'] : ''), strtolower(is_scalar($b['name']) ? (string) $b['name'] : ''));
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function tagAlphaCompare(array $a, array $b): int
    {
        foreach ([$a, $b] as $tag) {
            $tagName = is_scalar($tag['name']) ? (string) $tag['name'] : '';
            RequestCache::remember('tag_alpha', $tagName, static fn (): string => pwg_transliterate($tagName));
        }

        $aName = is_scalar($a['name']) ? (string) $a['name'] : '';
        $bName = is_scalar($b['name']) ? (string) $b['name'] : '';
        $aSlug = RequestCache::get('tag_alpha', $aName);
        $bSlug = RequestCache::get('tag_alpha', $bName);
        return strcmp(
            is_string($aSlug) ? $aSlug : '',
            is_string($bSlug) ? $bSlug : ''
        );
    }

    public function accessDenied(): void
    {
        if (CurrentUser::isInitialized() and !is_a_guest()) {
            $this->setStatusHeader(401);

            echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<link rel="shortcut icon" type="image/x-icon" href="themes/default/icon/favicon.ico">
<div style="display: flex; justify-content: center;align-items: center;height: 100vh;margin: 0;color: #3C3C3C;font-family: \'Open Sans\', sans-serif;font-size: 20px;font-style: normal;font-weight: 600;line-height: normal;">
  <div style="text-align:center;">
    <img src="themes/default/icon/warning-triangle.svg" alt="warning-triangle" >
    <p style="max-width: 400px; margin-top 20px;">' . l10n('You are not authorized to access the requested page') . '</p>
    <a href="' . make_index_url() . '" style="display: inline-block;padding: 10px 20px;margin: 10px;margin-top: 50px;border-radius: 7px;cursor: pointer;width: 150px;background-color: #F77000;color: #fff;text-decoration: none;border: 2px solid #F77000;">' . l10n('Home') . '</a>
  </div>
</div>';
            exit();
        }

        redirect_http(get_root_url() . 'identification.php?redirect=' . urlencode(urlencode(is_scalar($_SERVER['REQUEST_URI'] ?? null) ? (string) $_SERVER['REQUEST_URI'] : '')));
    }

    public function pageForbidden(string $msg, ?string $alternateUrl = null): void
    {
        $this->setStatusHeader(403);
        $redirectUrl = $alternateUrl ?? make_index_url();
        redirect_html(
            $redirectUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . l10n('Forbidden') . '</h1><br>' . $msg . '</div>',
            5
        );
    }

    public function badRequest(string $msg, ?string $alternateUrl = null): void
    {
        $this->setStatusHeader(400);
        $redirectUrl = $alternateUrl ?? make_index_url();
        redirect_html(
            $redirectUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . l10n('Bad request') . '</h1><br>' . $msg . '</div>',
            5
        );
    }

    public function pageNotFound(?string $msg, ?string $alternateUrl = null): void
    {
        $this->setStatusHeader(404);
        $redirectUrl = $alternateUrl ?? make_index_url();
        redirect_html(
            $redirectUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . l10n('Page not found') . '</h1><br>' . $msg . '</div>',
            5
        );
    }

    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        if (empty($title)) {
            $title = l10n('Piwigo encountered a non recoverable error');
        }

        $btraceMsg = '';
        if ($showTrace and function_exists('debug_backtrace')) {
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
            $this->setStatusHeader(500);
        }
        echo $display . str_repeat(' ', 300);

        if (function_exists('ini_set')) {
            ini_set('display_errors', false);
        }
        error_reporting(E_ALL);
        throw new \RuntimeException(strip_tags($msg) . $btraceMsg);
    }

    public function getTagsContentTitle(): string
    {
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        $tags = is_array($page['tags'] ?? null) ? $page['tags'] : [];
        $title = '<a href="' . get_root_url() . 'tags.php" title="' . l10n('display available tags') . '">'
          . l10n(count($tags) > 1 ? 'Tags' : 'Tag')
          . '</a> ';

        return $title;
    }

    public function getCombinedCategoriesContentTitle(): string
    {
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

        $title    = l10n('Albums') . ' ';
        $isFirst  = true;
        $combined = is_array($page['combined_categories'] ?? null) ? $page['combined_categories'] : [];
        $allCategories = array_merge([$page['category'] ?? []], $combined);
        foreach ($allCategories as $idx => $category) {
            $title  .= $isFirst ? '' : ' + ';
            $isFirst = false;

            $title .= $this->getCatDisplayName([$category]);

            if (count($allCategories) > 1) {
                $otherCats = $allCategories;
                unset($otherCats[$idx]);

                $params = ['category' => array_shift($otherCats)];

                if (count($otherCats) > 0) {
                    $params['combined_categories'] = $otherCats;
                }
                $removeUrl = make_index_url($params);

                $title .=
                  '<a id="TagsGroupRemoveTag" href="' . $removeUrl . '" style="border:none;" title="'
                  . l10n('remove this tag from the list')
                  . '"><img src="'
                    . get_root_url() . (is_string(get_themeconf('icon_dir')) ? get_themeconf('icon_dir') : '') . '/remove_s.png'
                  . '" alt="x" style="vertical-align:bottom;" >'
                  . '<span class="pwg-icon pwg-icon-close" ></span>'
                  . '</a>';
            }
        }

        return $title;
    }

    public function setStatusHeader(int $code, string $text = ''): void
    {
        if (empty($text)) {
            $text = match ($code) {
                200     => 'OK',
                301     => 'Moved permanently',
                302     => 'Moved temporarily',
                304     => 'Not modified',
                400     => 'Bad request',
                401     => 'Authorization required',
                403     => 'Forbidden',
                404     => 'Not found',
                500     => 'Server error',
                501     => 'Not implemented',
                503     => 'Service unavailable',
                default => '',
            };
        }
        $protocolRaw = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0';
        $protocol    = is_string($protocolRaw) ? $protocolRaw : 'HTTP/1.0';
        if (('HTTP/1.1' != $protocol) && ('HTTP/1.0' != $protocol)) {
            $protocol = 'HTTP/1.0';
        }

        header("$protocol $code $text", true, $code);
        trigger_notify('set_status_header', $code, $text);
    }

    public function renderCategoryLiteralDescription(?string $desc): string
    {
        return strip_tags($desc ?? '', '<span><p><a><br><b><i><small><big><strong><em>');
    }

    /** @param BlockManager[] $menuRefArr */
    public function registerDefaultMenubarBlocks(array $menuRefArr): void
    {
        $menu = &$menuRefArr[0];
        if ($menu->get_id() != 'menubar') {
            return;
        }
        $menu->register_block(new RegisteredBlock('mbLinks', 'Links', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbCategories', 'Albums', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbTags', 'Tags', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbSpecials', 'Specials', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbMenu', 'Menu', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbRelatedCategories', 'Related albums', 'piwigo'));

        if (script_basename() != 'identification') {
            $menu->register_block(new RegisteredBlock('mbIdentification', 'Identification', 'piwigo'));
        }
    }

    /** @param array<string, mixed> $info */
    public function renderElementName(array $info): string
    {
        if (!empty($info['name'])) {
            return (string) trigger_change('render_element_name', is_scalar($info['name']) ? (string) $info['name'] : '', $info);
        }
        return get_name_from_file(is_string($info['file'] ?? null) ? $info['file'] : '');
    }

    /** @param array<string, mixed> $info */
    public function renderElementDescription(array $info, string $param = ''): string
    {
        if (!empty($info['comment'])) {
            return (string) trigger_change('render_element_description', is_scalar($info['comment']) ? (string) $info['comment'] : '', $param);
        }
        return '';
    }

    /** @param array<string, mixed> $info */
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        $details = [];

        if (!empty($info['hit'])) {
            $details[] = l10n('%d visits', $info['hit']);
        }

        if (Config::rateEnabled() and !empty($info['rating_score'])) {
            $details[] = l10n('rating score %s', $info['rating_score']);
        }

        if (isset($info['nb_comments']) and $info['nb_comments'] != 0) {
            $details[] = l10n_dec('%d comment', '%d comments', is_numeric($info['nb_comments']) ? (int) $info['nb_comments'] : 0);
        }

        if (count($details) > 0) {
            $title .= ' (' . implode(', ', $details) . ')';
        }

        if (!empty($comment)) {
            $comment = strip_tags($comment);
            $title  .= ' ' . substr($comment, 0, 100) . (strlen($comment) > 100 ? '...' : '');
        }

        $title = htmlspecialchars(strip_tags($title));
        $title = trigger_change('get_thumbnail_title', $title, $info);

        return $title;
    }

    public function getSrcImageUrlProtectionHandler(string $url, SrcImage $srcImage): string
    {
        return get_action_url($srcImage->id, $srcImage->is_original() ? 'e' : 'r', false);
    }

    /** @param array<string, mixed> $infos */
    public function getElementUrlProtectionHandler(string $url, array $infos): string
    {
        if ('images' == Config::originalUrlProtection()) {
            $ext = get_extension(is_string($infos['path'] ?? null) ? $infos['path'] : '');
            if (!in_array($ext, Config::pictureExtensions())) {
                return $url;
            }
        }
        return get_action_url(is_int($infos['id'] ?? null) || is_string($infos['id'] ?? null) ? $infos['id'] : 0, 'e', false);
    }

    public function flushPageMessages(): void
    {
        $template = TemplateRegistry::current();
        if ($template->get_template_vars('page_refresh') !== null) {
            return;
        }
        $page = PageState::current();
        foreach (['errors', 'infos', 'warnings', 'messages'] as $mode) {
            $sessionKey = 'page_' . $mode;
            $sessionArr = (isset($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey]))
                ? array_values(array_filter($_SESSION[$sessionKey], is_string(...)))
                : [];
            if ($sessionArr !== []) {
                unset($_SESSION[$sessionKey]);
            }
            $current = match ($mode) {
                'errors'   => $page->errors   = array_merge($page->errors, $sessionArr),
                'infos'    => $page->infos    = array_merge($page->infos, $sessionArr),
                'warnings' => $page->warnings = array_merge($page->warnings, $sessionArr),
                'messages' => $page->messages = array_merge($page->messages, $sessionArr),
            };
            if ($current !== []) {
                $template->assign($mode, $current);
            }
        }
    }

    public function pwgNl2br(string $string): string
    {
        if (empty($string)) {
            return $string;
        }
        return nl2br($string);
    }
}
