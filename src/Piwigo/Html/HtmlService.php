<?php

declare(strict_types=1);

namespace Piwigo\Html;

use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Db\Tables;
use Piwigo\Image\SrcImage;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\RegisteredBlock;
use Piwigo\Template\Template;

/**
 * HTML rendering helpers, error pages, and status/header utilities.
 *
 * Injects nothing -- same "no constructor deps" shape as Piwigo\Url\
 * UrlService (its P17 sibling namespace): the remaining bare calls
 * (trigger_change(), get_cat_info(), get_root_url()/add_url_params(),
 * l10n()) are all settled composer-autoloaded utilities, not unmigrated
 * legacy.
 *
 * Implements HtmlRenderingInterface (P23 batch 8f-3) so L1/L2a/L2b classes
 * that can't depend on this L3Presentation class directly can depend on
 * that interface instead -- see its own docblock.
 */
final class HtmlService implements HtmlRenderingInterface
{
    /**
     * Generates breadcrumb from categories list.
     * Categories string returned contains categories as given in the input
     * array $catInformations. $catInformations array must be an array
     * of array(id=>?, name=>?, permalink=>?). If url input parameter is
     * null, returns only the categories name without links.
     *
     * @param array<int, array<string, mixed>> $catInformations
     */
    #[\Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        $level_separator = \Piwigo\Config\Config::levelSeparator();

        $output = '';
        $is_first = true;

        foreach ($catInformations as $cat) {
            $cat['name'] = trigger_change(
                'render_category_name',
                $cat['name'],
                'get_cat_display_name',
            );
            // trigger_change()'s own return type is mixed; category names are
            // always strings, but a misbehaving handler shouldn't propagate a
            // non-string value into the markup built below.
            if (! is_string($cat['name'])) {
                $cat['name'] = '';
            }

            if ($is_first) {
                $is_first = false;
            } else {
                $output .= $level_separator;
            }

            if (! isset($url)) {
                $output .= $cat['name'];
            } elseif ($url === '') {
                $output .= '<a href="'
                      . make_index_url(
                          [
                              'category' => $cat,
                          ],
                      )
                      . '">';
                $output .= $cat['name'] . '</a>';
            } else {
                $cat_id = is_scalar($cat['id']) ? (string) $cat['id'] : '';
                $output .= '<a href="' . PHPWG_ROOT_PATH . $url . $cat_id . '">';
                $output .= $cat['name'] . '</a>';
            }
        }

        return $output;
    }

    /**
     * Generates breadcrumb from categories list using a cache.
     * @see getCatDisplayName()
     */
    #[\Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        /**
         * @var array<string, mixed>
         */
        global $cache;
        $level_separator = \Piwigo\Config\Config::levelSeparator();

        $add_url_params = [];
        if (isset($authKey)) {
            $add_url_params['auth'] = $authKey;
        }

        if (! isset($cache['cat_names'])) {
            $query = '
SELECT id, name, permalink
  FROM ' . Tables::categories() . '
;';
            $cache['cat_names'] = \Piwigo\Db\MysqliDb::query2Array($query, 'id');
        }
        // Narrowed once here (fix pattern #7): $cache is array<string, mixed>,
        // proving $cache is array-like does not prove $cache['cat_names'] is
        // also array-like, since the declared value type is mixed.
        $cat_names = is_array($cache['cat_names']) ? $cache['cat_names'] : [];

        $output = '';
        if ($singleLink) {
            $uppercats_array = explode(',', $uppercats);
            $single_url = add_url_params(get_root_url() . $url . array_pop($uppercats_array), $add_url_params);
            $output .= '<a href="' . $single_url . '"';
            if (isset($linkClass)) {
                $output .= ' class="' . $linkClass . '"';
            }
            $output .= '>';
        }
        $is_first = true;
        foreach (explode(',', $uppercats) as $category_id) {
            $cat = $cat_names[$category_id] ?? null;
            $cat = is_array($cat) ? $cat : [];

            $cat['name'] = trigger_change(
                'render_category_name',
                $cat['name'],
                'get_cat_display_name_cache',
            );
            // trigger_change()'s own return type is mixed; category names are
            // always strings, but a misbehaving handler shouldn't propagate a
            // non-string value into the markup built below.
            if (! is_string($cat['name'])) {
                $cat['name'] = '';
            }

            if ($is_first) {
                $is_first = false;
            } else {
                $output .= '<span>' . $level_separator . '</span>';
            }

            if (! isset($url) or $singleLink) {
                $output .= $cat['name'];
            } elseif ($url === '') {
                $output .= '
<a href="'
                . add_url_params(
                    make_index_url(
                        [
                            'category' => $cat,
                        ],
                    ),
                    $add_url_params,
                )
                . '">' . $cat['name'] . '</a>';
            } else {
                $output .= '
<a href="' . PHPWG_ROOT_PATH . $url . $category_id . '">' . $cat['name'] . '</a>';
            }
        }

        if ($singleLink) {
            $output .= '</a>';
        }

        return $output;
    }

    /**
     * Generates breadcrumb for a category.
     * @see getCatDisplayName()
     */
    public function getCatDisplayNameFromId(int $catId, ?string $url = ''): string
    {
        $cat_info = get_cat_info($catId);
        // $catId isn't existence-validated by callers (WS/URL param) -- a
        // stale/forged id falls back to an empty breadcrumb.
        $upper_names = $cat_info['upper_names'] ?? [];
        // get_cat_info()'s return type is the generic array<string, mixed>, but
        // its 'upper_names' key (the only producer, verified in
        // functions_category.inc.php) is always built as a list of category-row
        // arrays with string keys (id, name, permalink) -- never anything else.
        $upper_names = is_array($upper_names) ? $upper_names : [];
        /** @var array<int, array<string, mixed>> $upper_names */
        return $this->getCatDisplayName($upper_names, $url);
    }

    /**
     * Apply basic markdown transformations to a text.
     * newlines becomes br tags
     * _word_ becomes underline
     * /word/ becomes italic
     * *word* becomes bolded
     * urls becomes a tags
     */
    public function renderCommentContent(string $content): ?string
    {
        $content = htmlspecialchars($content);
        $pattern = '/(https?:\/\/\S*)/';
        $replacement = '<a href="$1" rel="nofollow">$1</a>';
        $content = preg_replace($pattern, $replacement, $content);

        $content = nl2br((string) $content);

        // replace _word_ by an underlined word
        $pattern = '/\b_(\S*)_\b/';
        $replacement = '<span style="text-decoration:underline;">$1</span>';
        $content = preg_replace($pattern, $replacement, $content);

        // replace *word* by a bolded word
        $pattern = '/\b\*(\S*)\*\b/';
        $replacement = '<span style="font-weight:bold;">$1</span>';
        $content = preg_replace($pattern, $replacement, (string) $content);

        // replace /word/ by an italic word
        $pattern = "/\/(\S*)\/(\s)/";
        $replacement = '<span style="font-style:italic;">$1$2</span>';
        $content = preg_replace($pattern, $replacement, (string) $content);

        // TODO : add a trigger

        return $content;
    }

    /**
     * Callback used for sorting by name.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    #[\Override]
    public function nameCompare(array $a, array $b): int
    {
        $name_a = is_string($a['name'] ?? null) ? $a['name'] : '';
        $name_b = is_string($b['name'] ?? null) ? $b['name'] : '';

        return strcmp(strtolower($name_a), strtolower($name_b));
    }

    /**
     * Callback used for sorting by name (slug) with cache.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    #[\Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        /** @var array<string, mixed> $cache */
        global $cache;

        $name_a = is_string($a['name'] ?? null) ? $a['name'] : '';
        $name_b = is_string($b['name'] ?? null) ? $b['name'] : '';

        // Narrowed once here (fix pattern #7): $cache is array<string, mixed>,
        // so $cache[self::class] is still mixed even after $cache is typed.
        $transliterated = is_array($cache[self::class . '::tagAlphaCompare'] ?? null)
            ? $cache[self::class . '::tagAlphaCompare']
            : [];

        foreach ([$name_a, $name_b] as $tag_name) {
            // pwg_transliterate() always returns string, so a cached entry that
            // isn't a string was never written by this loop and must be
            // (re)computed -- a real runtime guard equivalent to the original
            // isset() check (fix pattern #6).
            if (! is_string($transliterated[$tag_name] ?? null)) {
                $transliterated[$tag_name] = \Piwigo\Core\StringHelper::pwgTransliterate($tag_name);
            }
        }

        $cache[self::class . '::tagAlphaCompare'] = $transliterated;

        $translit_a = is_string($transliterated[$name_a] ?? null) ? $transliterated[$name_a] : \Piwigo\Core\StringHelper::pwgTransliterate($name_a);
        $translit_b = is_string($transliterated[$name_b] ?? null) ? $transliterated[$name_b] : \Piwigo\Core\StringHelper::pwgTransliterate($name_b);

        return strcmp($translit_a, $translit_b);
    }

    /**
     * Exits the current script.
     */
    #[\Override]
    public function accessDenied(): never
    {
        if (\Piwigo\Users\CurrentUser::isInitialized() and ! \Piwigo\Auth\AccessControl::isAGuest()) {
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

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $request_uri = is_string($request_uri) ? $request_uri : '';
        redirect_http(get_root_url() . 'identification.php?redirect=' . urlencode(urlencode($request_uri)));
    }

    /**
     * Exits the current script with 403 code.
     * @todo nice display if $template loaded
     */
    public function pageForbidden(string $msg, ?string $alternateUrl = null): never
    {
        $this->setStatusHeader(403);
        if ($alternateUrl === null) {
            $alternateUrl = make_index_url();
        }
        redirect_html(
            $alternateUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . l10n('Forbidden') . '</h1><br>'
. $msg . '</div>',
            5,
        );
    }

    /**
     * Exits the current script with 400 code.
     * @todo nice display if $template loaded
     */
    #[\Override]
    public function badRequest(string $msg, ?string $alternateUrl = null): never
    {
        $this->setStatusHeader(400);
        if ($alternateUrl === null) {
            $alternateUrl = make_index_url();
        }
        redirect_html(
            $alternateUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . l10n('Bad request') . '</h1><br>'
. $msg . '</div>',
            5,
        );
    }

    /**
     * Exits the current script with 404 code.
     * @todo nice display if $template loaded
     *
     * @param string|null $msg null is treated the same as '' below (string
     *   concatenation); comments.php passes null when comments are disabled
     */
    #[\Override]
    public function pageNotFound(?string $msg, ?string $alternateUrl = null): never
    {
        $this->setStatusHeader(404);
        if ($alternateUrl === null) {
            $alternateUrl = make_index_url();
        }
        redirect_html(
            $alternateUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . l10n('Page not found') . '</h1><br>'
. $msg . '</div>',
            5,
        );
    }

    /**
     * Exits the current script with 500 code.
     * @todo nice display if $template loaded
     */
    #[\Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        if ($title === null || $title === '') {
            $title = l10n('Piwigo encountered a non recoverable error');
        }

        $btrace_msg = '';
        if ($showTrace and function_exists('debug_backtrace')) {
            $bt = debug_backtrace();
            for ($i = 1; $i < count($bt); $i++) {
                $class = isset($bt[$i]['class']) ? (@$bt[$i]['class'] . '::') : '';
                $btrace_msg .= "#{$i}\t" . $class . $bt[$i]['function'] . ' ' . ($bt[$i]['file'] ?? '') . '(' . ($bt[$i]['line'] ?? '') . ")\n";
            }
            $btrace_msg = trim($btrace_msg);
            $msg .= "\n";
        }

        $display = "<meta http-equiv='Content-Type' content='text/html; charset=utf-8'>
<h1>{$title}</h1>
<pre style='font-size:larger;background:white;color:red;padding:1em;margin:0;clear:both;display:block;width:auto;height:auto;overflow:auto'>
<b>{$msg}</b>
{$btrace_msg}
</pre>\n";

        @$this->setStatusHeader(500);
        echo $display . str_repeat(' ', 300); // IE6 doesn't error output if below a size

        if (function_exists('ini_set')) { // if possible turn off error display (we display it)
            ini_set('display_errors', false);
        }
        error_reporting(E_ALL);
        trigger_error(strip_tags($msg) . $btrace_msg, E_USER_ERROR);
        // Genuinely reachable, not just defensive: include/error_collector.inc.php
        // installs a set_error_handler() that intercepts E_USER_ERROR and returns
        // true (suppressing PHP's normal fatal-and-terminate behavior), so
        // trigger_error() above can actually return here when that handler is
        // active (installed for every real request via common.inc.php) — static
        // analysis has no way to know set_error_handler() changes this.
        // @phpstan-ignore deadCode.unreachable
        die(0);
    }

    /**
     * Returns the breadcrumb to be displayed above thumbnails on tag page.
     */
    #[\Override]
    public function getTagsContentTitle(): string
    {
        /** @var array<string, mixed> $page */
        global $page;

        $tags = is_array($page['tags'] ?? null) ? $page['tags'] : [];

        return '<a href="' . get_root_url() . 'tags.php" title="' . l10n('display available tags') . '">'
          . l10n(count($tags) > 1 ? 'Tags' : 'Tag')
          . '</a> ';
    }

    /**
     * Returns the breadcrumb to be displayed above thumbnails on combined
     * categories page.
     */
    #[\Override]
    public function getCombinedCategoriesContentTitle(): string
    {
        /** @var array<string, mixed> $page */
        global $page;

        $title = l10n('Albums') . ' ';

        // Narrowed once here (fix pattern #7): $page is array<string, mixed>,
        // so $page['combined_categories'] is still mixed even after $page is
        // typed.
        $combined_categories = is_array($page['combined_categories'] ?? null) ? $page['combined_categories'] : [];
        $is_first = true;
        $all_categories = array_merge([$page['category']], $combined_categories);
        foreach ($all_categories as $idx => $category) {
            $category = is_array($category) ? $category : [];
            /** @var array<string, mixed> $category */
            $title .= $is_first ? '' : ' + ';
            $is_first = false;

            $title .= $this->getCatDisplayName([$category]);

            if (count($all_categories) > 1) { // should be always the case
                $other_cats = $all_categories;
                unset($other_cats[$idx]);

                $params = [
                    'category' => array_shift($other_cats),
                ];

                if (count($other_cats) > 0) {
                    $params['combined_categories'] = $other_cats;
                }
                $remove_url = make_index_url($params);

                // P23 batch 8f-4: replaces the deleted get_themeconf()
                // free function -- this class is L3Presentation, so it may
                // read the request's Template instance (also L3) directly,
                // no ThemeConfProviderInterface indirection needed (unlike
                // SrcImage, L2a). $GLOBALS['template'] is always a real
                // Template on any request that renders this markup.
                $request_template = $GLOBALS['template'] ?? null;
                $icon_dir = $request_template instanceof Template ? $request_template->themeConf('icon_dir') : '';

                $title .=
                  '<a id="TagsGroupRemoveTag" href="' . $remove_url . '" style="border:none;" title="'
                  . l10n('remove this tag from the list')
                  . '"><img src="'
                    . get_root_url() . $icon_dir . '/remove_s.png'
                  . '" alt="x" style="vertical-align:bottom;" >'
                  . '<span class="pwg-icon pwg-icon-close" ></span>'
                  . '</a>';
            }
        }

        return $title;
    }

    /**
     * Sets the http status header (200,401,...).
     */
    #[\Override]
    public function setStatusHeader(int $code, string $text = ''): void
    {
        if ($text === '') {
            $text = match ($code) {
                200 => 'OK',
                301 => 'Moved permanently',
                302 => 'Moved temporarily',
                304 => 'Not modified',
                400 => 'Bad request',
                401 => 'Authorization required',
                403 => 'Forbidden',
                404 => 'Not found',
                500 => 'Server error',
                501 => 'Not implemented',
                503 => 'Service unavailable',
                default => $text,
            };
        }
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? '';
        $protocol = is_string($protocol) ? $protocol : '';
        if (($protocol !== 'HTTP/1.1') && ($protocol !== 'HTTP/1.0')) {
            $protocol = 'HTTP/1.0';
        }

        header("{$protocol} {$code} {$text}", true, $code);
        trigger_notify('set_status_header', $code, $text);
    }

    /**
     * Returns the category comment for rendering in html textual mode
     * (subcatify). This method is called by a trigger_notify().
     */
    public function renderCategoryLiteralDescription(?string $desc): string
    {
        if (! isset($desc)) {
            $desc = '';
        }

        return strip_tags($desc, '<span><p><a><br><b><i><small><big><strong><em>');
    }

    /**
     * Add known menubar blocks.
     * This method is called by a trigger_change().
     *
     * @param BlockManager[] $menuRefArr
     */
    public function registerDefaultMenubarBlocks(array $menuRefArr): void
    {
        $menu = &$menuRefArr[0];
        if ($menu->get_id() !== 'menubar') {
            return;
        }
        $menu->register_block(new RegisteredBlock('mbLinks', 'Links', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbCategories', 'Albums', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbTags', 'Tags', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbSpecials', 'Specials', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbMenu', 'Menu', 'piwigo'));
        $menu->register_block(new RegisteredBlock('mbRelatedCategories', 'Related albums', 'piwigo'));

        // We hide the quick identification menu on the identification page. It
        // would be confusing.
        if (\Piwigo\Core\PageFilterHelper::scriptBasename() !== 'identification') {
            $menu->register_block(new RegisteredBlock('mbIdentification', 'Identification', 'piwigo'));
        }
    }

    /**
     * Returns display name for an element.
     * Returns 'name' if exists of name from 'file'.
     *
     * @param array<string, mixed> $info at least file or name
     */
    #[\Override]
    public function renderElementName(array $info): string
    {
        if (isset($info['name']) && is_string($info['name']) && $info['name'] !== '') {
            $rendered_name = trigger_change('render_element_name', $info['name'], $info);
            // trigger_change()'s own return type is mixed; fall back to the
            // pre-trigger name if a misbehaving handler returns something else.
            return is_string($rendered_name) ? $rendered_name : $info['name'];
        }
        $filename = $info['file'] ?? null;

        return \Piwigo\Core\StringHelper::getNameFromFile(is_string($filename) ? $filename : '');
    }

    /**
     * Returns display description for an element.
     *
     * @param array<string, mixed> $info at least comment
     * @param string $param used to identify the trigger
     */
    #[\Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        if (isset($info['comment']) && is_string($info['comment']) && $info['comment'] !== '') {
            $rendered_comment = trigger_change('render_element_description', $info['comment'], $param);
            // trigger_change()'s own return type is mixed; fall back to the
            // pre-trigger comment if a misbehaving handler returns something
            // else.
            return is_string($rendered_comment) ? $rendered_comment : $info['comment'];
        }

        return '';
    }

    /**
     * Add info to the title of the thumbnail based on photo properties.
     *
     * @param array<string, mixed> $info hit, rating_score, nb_comments
     */
    #[\Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {

        $details = [];

        if (isset($info['hit']) && is_numeric($info['hit']) && (int) $info['hit'] !== 0) {
            $details[] = l10n('%d visits', $info['hit']);
        }

        if (\Piwigo\Config\Config::rateEnabled() and isset($info['rating_score']) && is_numeric($info['rating_score']) && (float) $info['rating_score'] !== 0.0) {
            $details[] = l10n('rating score %s', $info['rating_score']);
        }

        if (isset($info['nb_comments']) and is_numeric($info['nb_comments']) and (int) $info['nb_comments'] !== 0) {
            $details[] = l10n_dec('%d comment', '%d comments', (int) $info['nb_comments']);
        }

        if (count($details) > 0) {
            $title .= ' (' . implode(', ', $details) . ')';
        }

        if ($comment !== '') {
            $comment = strip_tags($comment);
            $title .= ' ' . substr($comment, 0, 100) . (strlen($comment) > 100 ? '...' : '');
        }

        $title = htmlspecialchars(strip_tags($title));
        $rendered_title = trigger_change('get_thumbnail_title', $title, $info);

        // trigger_change()'s own return type is mixed; fall back to the
        // pre-trigger title if a misbehaving handler returns something else.
        return is_string($rendered_title) ? $rendered_title : $title;
    }

    /**
     * Event handler to protect src image urls.
     */
    public function getSrcImageUrlProtectionHandler(string $url, SrcImage $srcImage): string
    {
        return get_action_url($srcImage->id, $srcImage->is_original() ? 'e' : 'r', false);
    }

    /**
     * Event handler to protect element urls.
     *
     * @param array<string, mixed> $infos id, path
     */
    public function getElementUrlProtectionHandler(string $url, array $infos): string
    {
        if (\Piwigo\Config\Config::originalUrlProtection() === 'images') { // protect only images and not other file types (for example large movies that we don't want to send through our file proxy)
            $path = $infos['path'] ?? null;
            $ext = \Piwigo\Core\StringHelper::getExtension(is_string($path) ? $path : null);
            $picture_ext = is_array(\Piwigo\Config\Config::pictureExtensions()) ? \Piwigo\Config\Config::pictureExtensions() : [];
            if (! in_array($ext, $picture_ext, true)) {
                return $url;
            }
        }
        $id = $infos['id'] ?? '';
        $id = is_int($id) || is_string($id) ? $id : '';

        return get_action_url($id, 'e', false);
    }

    /**
     * Sends to the template all messages stored in $page and in the session.
     */
    public function flushPageMessages(): void
    {
        /**
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();
        if ($template->get_template_vars('page_refresh') === null) {
            foreach (['errors', 'infos', 'warnings', 'messages'] as $mode) {
                // Narrowed once here (fix pattern #7): $page is
                // array<string, mixed>, so $page[$mode] is still mixed even
                // after $page is typed.
                $page_messages = is_array($page[$mode] ?? null) ? $page[$mode] : [];

                // Every writer of $_SESSION['page_*'] elsewhere in the codebase
                // (comments.php, picture.php, admin/batch_manager*.php, ...)
                // guards with is_array() before appending, so this mirrors that
                // same invariant instead of trusting the superglobal's mixed
                // element type.
                if (isset($_SESSION['page_' . $mode]) and is_array($_SESSION['page_' . $mode])) {
                    $page_messages = array_merge($page_messages, $_SESSION['page_' . $mode]);
                    unset($_SESSION['page_' . $mode]);
                }
                $page[$mode] = $page_messages;

                if ($page_messages !== []) {
                    $template->assign($mode, $page_messages);
                }
            }
        }
    }

    /**
     * pwgNl2br is useful for PHP 5.2 which doesn't accept more than 1
     * parameter on nl2br() (and anyway the second parameter of nl2br does not
     * match what Piwigo gives.
     *
     * @param array<int|string, mixed>|null|int|float|false|string $string
     * @return array<int|string, mixed>|null|int|float|false|string
     */
    public function pwgNl2br(mixed $string): array|null|int|float|false|string
    {
        if ($string === null || $string === '' || $string === 0 || $string === 0.0 || $string === false || $string === []) {
            return $string;
        }

        if (is_array($string)) {
            return $string;
        }

        return nl2br((string) $string);
    }
}
