<?php

declare(strict_types=1);

namespace Piwigo\Html;

use Doctrine\DBAL\Connection;
use Piwigo\Cache\RequestCache;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\Event\Picture\GetThumbnailTitle;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Event\Template\SetStatusHeader;
use Piwigo\Http\RedirectResponder;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Menu\BlockManager;
use Piwigo\Menu\RegisteredBlock;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Theme\ThemeService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class HtmlService
{
    public function __construct(
        private Connection $conn,
        private StringUtil $stringUtil,
        private EventDispatcherInterface $dispatcher,
    ) {
    }
    /** @param array<mixed> $catInformations */
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        $output = '';
        $isFirst = true;

        foreach ($catInformations as $cat) {
            if (!is_array($cat)) {
                throw new \InvalidArgumentException('get_cat_display_name wrong type for category');
            }

            $catRenderEvent = new RenderCategoryName(
                is_string($cat['name'] ?? null) ? $cat['name'] : '',
                'get_cat_display_name'
            );
            $this->dispatcher->dispatch($catRenderEvent);
            $cat['name'] = $catRenderEvent->categoryName;

            if ($isFirst) {
                $isFirst = false;
            } else {
                $output .= Config::levelSeparator();
            }

            if (!isset($url)) {
                $output .= $cat['name'];
            } elseif ($url == '') {
                $output .= '<a href="' . Kernel::service(UrlService::class)->makeIndexUrl(['category' => $cat]) . '">';
                $output .= $cat['name'] . '</a>';
            } else {
                $catIdRaw = $cat['id'] ?? null;
                $output .= '<a href="' . PHPWG_ROOT_PATH . $url . (is_string($catIdRaw) ? $catIdRaw : '') . '">';
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

        $catNamesRaw = RequestCache::remember('cat_names', 'all', function (): array {
            $query = '
SELECT id, name, permalink
  FROM ' . Tables::categories() . '
;';
            return array_column($this->conn->executeQuery($query)->fetchAllAssociative(), null, 'id');
        });
        /** @var array<int|string, array<string,mixed>> $catNames */
        $catNames = is_array($catNamesRaw) ? $catNamesRaw : [];

        $output = '';
        if ($singleLink) {
            $uppercatsArray = explode(',', $uppercats);
            $lastCat = array_pop($uppercatsArray);
            $singleUrl      = Kernel::service(UrlService::class)->addUrlParams(UrlService::getRootUrl() . ($url ?? '') . $lastCat, $addUrlParamsArr);
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

            $catCacheRenderEvent = new RenderCategoryName(
                is_string($cat['name'] ?? null) ? $cat['name'] : '',
                'get_cat_display_name_cache'
            );
            $this->dispatcher->dispatch($catCacheRenderEvent);
            $cat['name'] = $catCacheRenderEvent->categoryName;

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
<a href="' . Kernel::service(UrlService::class)->addUrlParams(Kernel::service(UrlService::class)->makeIndexUrl(['category' => $cat]), $addUrlParamsArr) . '">' . $catName . '</a>';
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
        $catInfo    = Kernel::service(CategoryService::class)->getCatInfo($catId);
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
        return strcmp(strtolower(is_string($a['name'] ?? null) ? $a['name'] : ''), strtolower(is_string($b['name'] ?? null) ? $b['name'] : ''));
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public function tagAlphaCompare(array $a, array $b): int
    {
        foreach ([$a, $b] as $tag) {
            $tagName = is_string($tag['name'] ?? null) ? $tag['name'] : '';
            RequestCache::remember('tag_alpha', $tagName, fn (): string => $this->stringUtil->pwgTransliterate($tagName));
        }

        $aName = is_string($a['name'] ?? null) ? $a['name'] : '';
        $bName = is_string($b['name'] ?? null) ? $b['name'] : '';
        $aSlug = RequestCache::get('tag_alpha', $aName);
        $bSlug = RequestCache::get('tag_alpha', $bName);
        return strcmp(
            is_string($aSlug) ? $aSlug : '',
            is_string($bSlug) ? $bSlug : ''
        );
    }

    public function accessDenied(): void
    {
        if (CurrentUser::isInitialized() and !Kernel::service(PermissionService::class)->isAGuest()) {
            $this->setStatusHeader(401);

            echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<link rel="shortcut icon" type="image/x-icon" href="themes/_base/icon/favicon.ico">
<div style="display: flex; justify-content: center;align-items: center;height: 100vh;margin: 0;color: #3C3C3C;font-family: \'Open Sans\', sans-serif;font-size: 20px;font-style: normal;font-weight: 600;line-height: normal;">
  <div style="text-align:center;">
    <img src="themes/_base/icon/warning-triangle.svg" alt="warning-triangle" >
    <p style="max-width: 400px; margin-top 20px;">' . Lang::t('You are not authorized to access the requested page') . '</p>
    <a href="' . Kernel::service(UrlService::class)->makeIndexUrl() . '" style="display: inline-block;padding: 10px 20px;margin: 10px;margin-top: 50px;border-radius: 7px;cursor: pointer;width: 150px;background-color: #F77000;color: #fff;text-decoration: none;border: 2px solid #F77000;">' . Lang::t('Home') . '</a>
  </div>
</div>';
            exit();
        }

        /** @var mixed $rawRequestUri */
        $rawRequestUri = $_SERVER['REQUEST_URI'] ?? '';
        $requestUri    = is_string($rawRequestUri) ? $rawRequestUri : '';
        Kernel::service(RedirectResponder::class)->redirectHttp(Kernel::service(UrlService::class)->addUrlParams(Kernel::service(UrlGenerator::class)->identification(), ['redirect' => urlencode($requestUri)]));
    }

    public function pageForbidden(string $msg): void
    {
        $this->setStatusHeader(403);
        $redirectUrl = Kernel::service(UrlService::class)->makeIndexUrl();
        Kernel::service(RedirectResponder::class)->redirectHtml(
            $redirectUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . Lang::t('Forbidden') . '</h1><br>' . $msg . '</div>',
            5
        );
    }

    public function badRequest(string $msg): void
    {
        $this->setStatusHeader(400);
        $redirectUrl = Kernel::service(UrlService::class)->makeIndexUrl();
        Kernel::service(RedirectResponder::class)->redirectHtml(
            $redirectUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . Lang::t('Bad request') . '</h1><br>' . $msg . '</div>',
            5
        );
    }

    public function pageNotFound(?string $msg, ?string $alternateUrl = null): void
    {
        $this->setStatusHeader(404);
        $redirectUrl = $alternateUrl ?? Kernel::service(UrlService::class)->makeIndexUrl();
        Kernel::service(RedirectResponder::class)->redirectHtml(
            $redirectUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . Lang::t('Page not found') . '</h1><br>' . ($msg ?? '') . '</div>',
            5
        );
    }

    /** @pre-boot Safe to call before Kernel::boot() — no DI container required. */
    public static function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        if ($title === null || $title === '') {
            $title = Lang::t('Piwigo encountered a non recoverable error');
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

        // Server-side log of the fatal so the message reaches the standard
        // SAPI error log, not just the HTML response. ExceptionHandler will
        // log again from the rethrow below if it propagates that far, but
        // some callers wrap the throw — log here to be safe.
        error_log('[Piwigo] fatalError: ' . trim(strip_tags($msg)) . ($btraceMsg !== '' ? "\n" . $btraceMsg : ''));

        if (!headers_sent()) {
            header('HTTP/1.0 500 Server error', true, 500);
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
        $tags = SectionContextRegistry::current()->tags;
        $title = '<a href="' . Kernel::service(UrlGenerator::class)->tagsPage() . '" title="' . Lang::t('display available tags') . '">'
          . Lang::t(count($tags) > 1 ? 'Tags' : 'Tag')
          . '</a> ';

        return $title;
    }

    public function getCombinedCategoriesContentTitle(): string
    {
        $ctx = SectionContextRegistry::current();

        $title    = Lang::t('Albums') . ' ';
        $isFirst  = true;
        $combined = $ctx->combinedCategories ?? [];
        $allCategories = array_merge([$ctx->category ?? []], $combined);
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
                $removeUrl = Kernel::service(UrlService::class)->makeIndexUrl($params);

                $title .=
                  '<a id="TagsGroupRemoveTag" href="' . $removeUrl . '" style="border:none;" title="'
                  . Lang::t('remove this tag from the list')
                  . '"><img src="'
                    . UrlService::getRootUrl() . (is_string(Kernel::service(ThemeService::class)->getThemeconf('icon_dir')) ? Kernel::service(ThemeService::class)->getThemeconf('icon_dir') : '') . '/remove_s.png'
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
        /** @var mixed $protocolRaw */
        $protocolRaw = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0';
        $protocol    = is_string($protocolRaw) ? $protocolRaw : 'HTTP/1.0';
        if (('HTTP/1.1' != $protocol) && ('HTTP/1.0' != $protocol)) {
            $protocol = 'HTTP/1.0';
        }

        header("$protocol $code $text", true, $code);
        $this->dispatcher->dispatch(new SetStatusHeader($code, $text));
    }

    public function renderCategoryLiteralDescription(?string $desc): string
    {
        return strip_tags($desc ?? '', '<span><p><a><br><b><i><small><big><strong><em>');
    }

    public function registerDefaultMenubarBlocks(BlockManager $menu): void
    {
        if ($menu->getId() != 'menubar') {
            return;
        }
        $menu->registerBlock(new RegisteredBlock('mbLinks', 'Links', 'piwigo'));
        $menu->registerBlock(new RegisteredBlock('mbCategories', 'Albums', 'piwigo'));
        $menu->registerBlock(new RegisteredBlock('mbTags', 'Tags', 'piwigo'));
        $menu->registerBlock(new RegisteredBlock('mbSpecials', 'Specials', 'piwigo'));
        $menu->registerBlock(new RegisteredBlock('mbMenu', 'Menu', 'piwigo'));
        $menu->registerBlock(new RegisteredBlock('mbRelatedCategories', 'Related albums', 'piwigo'));

        if (StringUtil::scriptBasename() != 'identification') {
            $menu->registerBlock(new RegisteredBlock('mbIdentification', 'Identification', 'piwigo'));
        }
    }

    /** @param array<string, mixed> $info */
    public function renderElementName(array $info): string
    {
        if (!empty($info['name'])) {
            $nameEvent = new RenderElementName(is_string($info['name']) ? $info['name'] : '', $info);
            $this->dispatcher->dispatch($nameEvent);
            return $nameEvent->elementName;
        }
        return $this->stringUtil->getNameFromFile(is_string($info['file'] ?? null) ? $info['file'] : '');
    }

    /** @param array<string, mixed> $info */
    public function renderElementDescription(array $info, string $param = ''): string
    {
        if (!empty($info['comment'])) {
            return (string) EventDispatcher::dispatch('render_element_description', is_string($info['comment']) ? $info['comment'] : '', $param);
        }
        return '';
    }

    /** @param array<string, mixed> $info */
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        $details = [];

        if (!empty($info['hit'])) {
            $details[] = Lang::t('%d visits', is_numeric($info['hit']) ? (int) $info['hit'] : 0);
        }

        if (Config::rateEnabled() and !empty($info['rating_score'])) {
            $details[] = Lang::t('rating score %s', is_numeric($info['rating_score']) ? (float) $info['rating_score'] : '');
        }

        if (isset($info['nb_comments']) and $info['nb_comments'] != 0) {
            $details[] = Translator::get()->plural('%d comment', '%d comments', is_numeric($info['nb_comments']) ? (int) $info['nb_comments'] : 0);
        }

        if (count($details) > 0) {
            $title .= ' (' . implode(', ', $details) . ')';
        }

        if (!empty($comment)) {
            $comment = strip_tags($comment);
            $title  .= ' ' . substr($comment, 0, 100) . (strlen($comment) > 100 ? '...' : '');
        }

        $title = htmlspecialchars(strip_tags($title));
        $titleEvent = new GetThumbnailTitle($title, $info);
        $this->dispatcher->dispatch($titleEvent);

        return $titleEvent->title;
    }

    public function getSrcImageUrlProtectionHandler(string $url, SrcImage $srcImage): string
    {
        return Kernel::service(UrlService::class)->getActionUrl($srcImage->id, $srcImage->isOriginal() ? 'e' : 'r', false);
    }

    /** @param array<string, mixed> $infos */
    public function getElementUrlProtectionHandler(string $url, array $infos): string
    {
        if ('images' == Config::originalUrlProtection()) {
            $ext = $this->stringUtil->getExtension(is_string($infos['path'] ?? null) ? $infos['path'] : '');
            if (!in_array($ext, Config::pictureExtensions())) {
                return $url;
            }
        }
        return Kernel::service(UrlService::class)->getActionUrl(is_int($infos['id'] ?? null) || is_string($infos['id'] ?? null) ? $infos['id'] : 0, 'e', false);
    }

    public function flushPageMessages(): void
    {
        $template = TemplateRegistry::current();
        if ($template->getTemplateVars('page_refresh') !== null) {
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
        $keyedErrors = $page->keyedErrors;
        if ($keyedErrors !== []) {
            $page->keyedErrors = [];
            $existing = $template->getTemplateVars('errors');
            $template->assign('errors', array_merge(is_array($existing) ? $existing : [], $keyedErrors));
        }
    }

    public function pwgNl2br(string $string): string
    {
        if (empty($string)) {
            return $string;
        }
        return nl2br($string);
    }

    /** @return array<int,string> */
    public function getPrivacyLevelOptions(): array
    {
        $options = [];
        $label   = '';
        foreach (array_reverse(Config::availablePermissionLevels()) as $level) {
            if ($level === 0) {
                $label = Lang::t('Everybody');
            } else {
                if (strlen($label)) {
                    $label .= ', ';
                }
                $label .= Lang::t(sprintf('Level %d', $level));
            }
            $options[$level] = $label;
        }
        return $options;
    }

    /** @return array<string,mixed>|false */
    public function getIcon(?string $date, bool $isChildDate = false): array|false
    {
        if ($date === null || $date === '') {
            return false;
        }
        $raw          = CurrentUser::get()->rawAttributes;
        $recentPeriod = is_scalar($raw['recent_period'] ?? null) ? (int) $raw['recent_period'] : 7;
        $title        = RequestCache::remember('get_icon', 'title', static fn (): string => Lang::t(
            'photos posted during the last %d days',
            $recentPeriod
        ));
        $icon = ['TITLE' => $title, 'IS_CHILD_DATE' => $isChildDate];
        if (RequestCache::has('get_icon', $date)) {
            return RequestCache::get('get_icon', $date) ? $icon : [];
        }
        $sqlRecentDate = RequestCache::remember(
            'get_icon',
            'sql_recent_date',
            function () use ($recentPeriod): string {
                $v = $this->conn->executeQuery('SELECT ' . SqlExpr::recentPeriodExpr((string) $recentPeriod))->fetchOne();
                return is_scalar($v) ? (string) $v : '';
            }
        );
        $isRecent = $date > $sqlRecentDate;
        RequestCache::set('get_icon', $date, $isRecent);
        return $isRecent ? $icon : [];
    }
}
