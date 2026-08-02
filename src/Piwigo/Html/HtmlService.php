<?php

declare(strict_types=1);

namespace Piwigo\Html;

use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Picture\GetThumbnailTitle;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Template\RenderCategoryLiteralDescription;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Event\Template\RenderCommentContent;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Menu\Event\BlockManagerRegisterBlocks;
use Piwigo\Menu\RegisteredBlock;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;

/**
 * HTML rendering helpers, error pages, and status/header utilities.
 *
 * Takes only an optional, lazy-defaulted CategoryRepository -- same
 * "no *required* constructor deps" shape as Piwigo\Url\UrlService (its
 * P17 sibling namespace). This class has hundreds of real
 * `new HtmlService()` construction sites, so getCatDisplayNameCache()'s
 * own CategoryRepository need (Legacy Coupling Retirement: DI+DBAL
 * migration, Phase 1b) follows MailService::$webmasterMailProvider's
 * established lazy-default pattern rather than a required param.
 *
 * accessDenied()/badRequest()/pageNotFound()/pageForbidden() (Legacy
 * Coupling Retirement Phase 4b) take Piwigo\Core\RedirectServiceInterface
 * as a required *method* parameter instead, rather than a constructor
 * dependency -- Piwigo\Bootstrap\RedirectService is L4Integration, and
 * this class (L3Presentation) may not depend on it directly per
 * deptrac.yaml's ruleset; the DI container's service-locator accessor on
 * Piwigo\Core\Kernel (the only other way to reach a concrete L4 instance
 * from here without a constructor dependency) is arch-test-restricted to
 * Bootstrap/ and index.php. Every real caller already holds (or can
 * trivially construct) a RedirectServiceInterface instance of its own to
 * pass through.
 *
 * The remaining Url-family calls (Legacy Coupling Retirement Phase 4c)
 * go through the private urlService() helper below -- a throwaway,
 * non-constructor `new UrlService($this)` per call, not a constructor
 * property: `UrlService` requires `HtmlRenderingInterface` (this class
 * implements it), so a constructor-injected `UrlServiceInterface` here
 * would close a real cycle (`UrlService -> HtmlRenderingInterface ->
 * HtmlService -> UrlServiceInterface -> UrlService`). PHP-DI's
 * reflection-based autowiring only ever inspects class constructors,
 * never ordinary methods, so a private helper method sidesteps that --
 * unlike an optional/nullable constructor property of the same type,
 * which PHP-DI may still attempt to autowire.
 *
 * Implements HtmlRenderingInterface (P23 batch 8f-3) so L1/L2a/L2b classes
 * that can't depend on this L3Presentation class directly can depend on
 * that interface instead -- see its own docblock.
 */
final class HtmlService implements HtmlRenderingInterface
{
    public function __construct(
        private readonly ?CategoryRepository $categoryRepo = null,
    ) {}

    private function categoryRepo(): CategoryRepository
    {
        return $this->categoryRepo
            ?? \Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Category\CategoryEntity::class);
    }

    private function urlService(): UrlServiceInterface
    {
        return new UrlService($this);
    }

    /**
     * Generates breadcrumb from categories list.
     * Categories string returned contains categories as given in the input
     * array $catInformations. $catInformations array must be an array
     * of array(id=>?, name=>?, permalink=>?). If url input parameter is
     * null, returns only the categories name without links.
     *
     * Cross-domain generic-row-reader rationale, same as
     * Category\CategoryService::compareByGlobalRank() -- confirmed by
     * tracing all 3 real call sites: CategoryService::getCategoryInfo()'s
     * own 'upper_names' (clean id/name/permalink), a raw `SELECT id, name,
     * permalink` query (name/permalink nullable there), and
     * GalleryController's own qsearch 'matching_cats' rows (Search
     * module's QResults::$all_cats, already mixed via EventDispatcher).
     *
     * @param array<int, array<string, mixed>> $catInformations
     */
    #[\Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        $level_separator = \Piwigo\Config\CurrentConfig::levelSeparator();

        $output = '';
        $is_first = true;

        foreach ($catInformations as $cat) {
            $nameEvent = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new RenderCategoryName(is_string($cat['name']) ? $cat['name'] : '', 'get_cat_display_name'));
            $cat['name'] = $nameEvent->categoryName;

            if ($is_first) {
                $is_first = false;
            } else {
                $output .= $level_separator;
            }

            if (! isset($url)) {
                $output .= $cat['name'];
            } elseif ($url === '') {
                $output .= '<a href="'
                      . $this->urlService()->makeIndexUrl(
                          [
                              'category' => $cat,
                          ],
                      )
                      . '">';
                $output .= $cat['name'] . '</a>';
            } else {
                $cat_id = is_scalar($cat['id']) ? (string) $cat['id'] : '';
                $output .= '<a href="' . $this->urlService()->getRootUrl() . $url . $cat_id . '">';
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
        $level_separator = \Piwigo\Config\CurrentConfig::levelSeparator();

        $add_url_params = [];
        if (isset($authKey)) {
            $add_url_params['auth'] = $authKey;
        }

        if (! \Piwigo\Core\ProcessCache::has('cat_names')) {
            \Piwigo\Core\ProcessCache::set('cat_names', $this->categoryRepo()->findAllIdNamePermalink());
        }
        // Narrowed once here (fix pattern #7): ProcessCache::get() returns
        // mixed, proving the key exists does not prove the stored value is
        // array-like.
        $cat_names_raw = \Piwigo\Core\ProcessCache::get('cat_names');
        $cat_names = is_array($cat_names_raw) ? $cat_names_raw : [];

        $output = '';
        if ($singleLink) {
            $uppercats_array = explode(',', $uppercats);
            $single_url = $this->urlService()
                ->addUrlParams($this->urlService()->getRootUrl() . $url . array_pop($uppercats_array), $add_url_params);
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

            $nameEvent = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new RenderCategoryName(is_string($cat['name'] ?? null) ? $cat['name'] : '', 'get_cat_display_name_cache'));
            $cat['name'] = $nameEvent->categoryName;

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
                . $this->urlService()->addUrlParams(
                    $this->urlService()
                        ->makeIndexUrl(
                            [
                                'category' => $cat,
                            ],
                        ),
                    $add_url_params,
                )
                . '">' . $cat['name'] . '</a>';
            } else {
                $output .= '
<a href="' . $this->urlService()->getRootUrl() . $url . $category_id . '">' . $cat['name'] . '</a>';
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
        // Throwaway CategoryService construction, not constructor injection
        // -- HtmlService can never depend on CategoryService (Legacy
        // Coupling Retirement Phase 4c: CategoryService needs
        // UrlServiceInterface, which needs HtmlRenderingInterface, which
        // HtmlService implements -- a real cycle). Reuses this class's own
        // lazy categoryRepo() rather than building a second repository.
        $categoryConn = DbConnection::build();
        $cat_info = new CategoryService(
            $this->categoryRepo(),
            new \Piwigo\Permission\PermissionService(
                new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($categoryConn)),
                \Piwigo\Db\EntityManagerFactory::build($categoryConn)->getRepository(\Piwigo\Group\GroupEntity::class),
                \Piwigo\Db\EntityManagerFactory::build($categoryConn)->getRepository(\Piwigo\Category\CategoryEntity::class)
            )
        )->getCategoryInfo($catId);
        // $catId isn't existence-validated by callers (WS/URL param) -- a
        // stale/forged id falls back to an empty breadcrumb.
        $upper_names = $cat_info['upper_names'] ?? [];
        return $this->getCatDisplayName($upper_names, $url);
    }

    /**
     * Apply basic markdown transformations to a text.
     * newlines becomes br tags
     * _word_ becomes underline
     * /word/ becomes italic
     * *word* becomes bolded
     * urls becomes a tags
     *
     * This method is itself the default handler for the real
     * `render_comment_content` plugin hook (registered via
     * `EventDispatcher::addTypedHandler()` in
     * `Bootstrap\RequestBootstrap::finalize()`) -- every real caller
     * (Ws\PwgComments, Picture\PictureCommentRenderer,
     * Controller\CommentsController) reaches it through
     * `dispatchChange(new RenderCommentContent(...))`, not directly.
     */
    public function renderCommentContent(RenderCommentContent $event): RenderCommentContent
    {
        $content = htmlspecialchars($event->commentContent);
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

        $event->commentContent = (string) $content;

        return $event;
    }

    /**
     * Callback used for sorting by name. Cross-domain generic-row-reader
     * rationale, same as Category\CategoryService::compareByGlobalRank()
     * -- real callers span Category/Tag/Search rows that merely share a
     * 'name' key.
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
     * Callback used for sorting by name (slug) with cache. Same
     * cross-domain generic-row-reader rationale as nameCompare() above.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    #[\Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        $name_a = is_string($a['name'] ?? null) ? $a['name'] : '';
        $name_b = is_string($b['name'] ?? null) ? $b['name'] : '';

        // Narrowed once here (fix pattern #7): ProcessCache::get() returns
        // mixed, so the stored value is still mixed even after this check.
        $transliterated_raw = \Piwigo\Core\ProcessCache::get(self::class . '::tagAlphaCompare');
        $transliterated = is_array($transliterated_raw) ? $transliterated_raw : [];

        foreach ([$name_a, $name_b] as $tag_name) {
            // pwg_transliterate() always returns string, so a cached entry that
            // isn't a string was never written by this loop and must be
            // (re)computed -- a real runtime guard equivalent to the original
            // isset() check (fix pattern #6).
            if (! is_string($transliterated[$tag_name] ?? null)) {
                $transliterated[$tag_name] = \Piwigo\Core\StringHelper::pwgTransliterate($tag_name);
            }
        }

        \Piwigo\Core\ProcessCache::set(self::class . '::tagAlphaCompare', $transliterated);

        $translit_a = is_string($transliterated[$name_a] ?? null) ? $transliterated[$name_a] : \Piwigo\Core\StringHelper::pwgTransliterate($name_a);
        $translit_b = is_string($transliterated[$name_b] ?? null) ? $transliterated[$name_b] : \Piwigo\Core\StringHelper::pwgTransliterate($name_b);

        return strcmp($translit_a, $translit_b);
    }

    /**
     * Workstream C3: throws Piwigo\Http\ResponseReadyException (a 401 page
     * or a redirect to the login page) instead of exiting directly -- see
     * that exception class's own docblock for why and where it's caught.
     */
    #[\Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        if (\Piwigo\Users\CurrentUser::isInitialized() and ! \Piwigo\Auth\AccessControl::isAGuest()) {
            $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<link rel="shortcut icon" type="image/x-icon" href="themes/default/icon/favicon.ico">
<div style="display: flex; justify-content: center;align-items: center;height: 100vh;margin: 0;color: #3C3C3C;font-family: \'Open Sans\', sans-serif;font-size: 20px;font-style: normal;font-weight: 600;line-height: normal;">
  <div style="text-align:center;">
    <img src="themes/default/icon/warning-triangle.svg" alt="warning-triangle" >
    <p style="max-width: 400px; margin-top 20px;">' . Lang::t('You are not authorized to access the requested page') . '</p>
    <a href="' . $this->urlService()->makeIndexUrl() . '" style="display: inline-block;padding: 10px 20px;margin: 10px;margin-top: 50px;border-radius: 7px;cursor: pointer;width: 150px;background-color: #F77000;color: #fff;text-decoration: none;border: 2px solid #F77000;">' . Lang::t('Home') . '</a>
  </div>
</div>';
            throw new ResponseReadyException(ResponseFactory::html($html, 401));
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $request_uri = is_string($request_uri) ? $request_uri : '';
        $redirectService->redirectHttp($this->urlService()->getRootUrl() . 'identification.php?redirect=' . urlencode(urlencode($request_uri)));
    }

    /**
     * Workstream C3: redirectHtml()'s own new $status param carries the
     * 403 through to the built Response instead of a separate
     * setStatusHeader() call.
     * @todo nice display if $template loaded
     */
    public function pageForbidden(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        if ($alternateUrl === null) {
            $alternateUrl = $this->urlService()
                ->makeIndexUrl();
        }
        $redirectService->redirectHtml(
            $alternateUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . Lang::t('Forbidden') . '</h1><br>'
. $msg . '</div>',
            5,
            403,
        );
    }

    /**
     * Workstream C3: redirectHtml()'s own new $status param carries the
     * 400 through to the built Response instead of a separate
     * setStatusHeader() call.
     * @todo nice display if $template loaded
     */
    #[\Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        if ($alternateUrl === null) {
            $alternateUrl = $this->urlService()
                ->makeIndexUrl();
        }
        $redirectService->redirectHtml(
            $alternateUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . Lang::t('Bad request') . '</h1><br>'
. $msg . '</div>',
            5,
            400,
        );
    }

    /**
     * Workstream C3: redirectHtml()'s own new $status param carries the
     * 404 through to the built Response instead of a separate
     * setStatusHeader() call.
     * @todo nice display if $template loaded
     *
     * @param string|null $msg null is treated the same as '' below (string
     *   concatenation); comments.php passes null when comments are disabled
     */
    #[\Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        if ($alternateUrl === null) {
            $alternateUrl = $this->urlService()
                ->makeIndexUrl();
        }
        $redirectService->redirectHtml(
            $alternateUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . Lang::t('Page not found') . '</h1><br>'
. $msg . '</div>',
            5,
            404,
        );
    }

    /**
     * Workstream C3: throws Piwigo\Http\ResponseReadyException (a 500
     * page) instead of exiting directly, after still logging via
     * ErrorCollector::recordFatal() -- see this method's own body for the
     * full reasoning (this used to be a trigger_error(E_USER_ERROR) call,
     * deprecated as of PHP 8.4).
     * @todo nice display if $template loaded
     */
    #[\Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        if ($title === null || $title === '') {
            $title = Lang::t('Piwigo encountered a non recoverable error');
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
        $display .= str_repeat(' ', 300); // IE6 doesn't error output if below a size

        if (function_exists('ini_set')) { // if possible turn off error display (we display it)
            ini_set('display_errors', false);
        }
        error_reporting(E_ALL);

        // Used to be trigger_error(strip_tags($msg) . $btrace_msg,
        // E_USER_ERROR) -- relying on ErrorCollector's installed
        // set_error_handler() to intercept E_USER_ERROR and return true
        // (suppressing PHP's normal fatal-and-terminate behavior) so
        // execution could continue to the throw below. PHP 8.4 deprecates
        // passing E_USER_ERROR to trigger_error() at all.
        //
        // An earlier version of this fix called exit() when ErrorCollector
        // wasn't active, reasoning that was the old mechanism's real
        // default behavior with no handler installed. That's true, but
        // wrong to imitate: ErrorCollector::installIfConfigured() only
        // installs when the deployment policy's showPhpErrorsOnFrontend is
        // *also* true -- a real, valid config (showPhpErrors on,
        // showPhpErrorsOnFrontend off) leaves isActive() false on every
        // request, where hard-exiting instead of returning the real 500
        // page below would be a genuine regression, not a faithful port.
        // It also broke this codebase's own established test pattern
        // (ScriptLoaderTest.php/TemplateInstanceTest.php among others)
        // of installing a throwaway set_error_handler() around a
        // fatalError()-reaching call and asserting on what it captured --
        // exit() bypasses every handler unconditionally, silently killing
        // the whole test process instead. Always recording (regardless of
        // isActive()) and always falling through to the real error page
        // below is both simpler and correct for every one of these cases.
        ErrorCollector::recordFatal(strip_tags($msg) . $btrace_msg);

        throw new ResponseReadyException(ResponseFactory::html($display, 500));
    }

    /**
     * Returns the breadcrumb to be displayed above thumbnails on tag page.
     *
     * Legacy Coupling Retirement Track A batch A5.2e: $tags is an
     * explicit param instead of `global $page['tags']` -- the one real
     * caller (SectionPopulator::populate()) calls this from within its
     * own execution, before any SectionContext exists yet to read via
     * SectionContextRegistry (an "in-flight collaborator", same shape as
     * CalendarRenderer/SearchFilterRenderer's own params).
     *
     * @param list<array<string, mixed>> $tags
     */
    #[\Override]
    public function getTagsContentTitle(array $tags): string
    {
        return '<a href="' . $this->urlService()->getRootUrl() . 'tags.php" title="' . Lang::t('display available tags') . '">'
          . Lang::t(count($tags) > 1 ? 'Tags' : 'Tag')
          . '</a> ';
    }

    /**
     * Returns the breadcrumb to be displayed above thumbnails on combined
     * categories page.
     *
     * Legacy Coupling Retirement Track A batch A5.2e: $category/
     * $combinedCategories are explicit params instead of
     * `global $page['category']`/`['combined_categories']` -- same
     * in-flight-collaborator reasoning as getTagsContentTitle() above.
     *
     * @param array<string, mixed>|null $category
     * @param list<array<string, mixed>> $combinedCategories
     */
    #[\Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        $title = Lang::t('Albums') . ' ';

        $is_first = true;
        $all_categories = array_merge([$category], $combinedCategories);
        foreach ($all_categories as $idx => $loopCategory) {
            $loopCategory = is_array($loopCategory) ? $loopCategory : [];
            /** @var array<string, mixed> $loopCategory */
            $title .= $is_first ? '' : ' + ';
            $is_first = false;

            $title .= $this->getCatDisplayName([$loopCategory]);

            if (count($all_categories) > 1) { // should be always the case
                $other_cats = $all_categories;
                unset($other_cats[$idx]);

                $params = [
                    'category' => array_shift($other_cats),
                ];

                if (count($other_cats) > 0) {
                    $params['combined_categories'] = $other_cats;
                }
                $remove_url = $this->urlService()
                    ->makeIndexUrl($params);

                // P23 batch 8f-4: replaces the deleted get_themeconf()
                // free function -- this class is L3Presentation, so it may
                // read the request's Template instance (also L3) directly,
                // no ThemeConfProviderInterface indirection needed (unlike
                // SrcImage, L2a). CurrentTemplate is always initialized on
                // any request that renders this markup, but this stays
                // defensive (Phase 2 global-residual sweep: retargeted from
                // $GLOBALS['template'] ?? null, same defensive shape).
                $request_template = \Piwigo\Template\CurrentTemplate::isInitialized() ? \Piwigo\Template\CurrentTemplate::get() : null;
                $icon_dir = $request_template instanceof Template ? $request_template->themeConf('icon_dir') : '';

                $title .=
                  '<a id="TagsGroupRemoveTag" href="' . $remove_url . '" style="border:none;" title="'
                  . Lang::t('remove this tag from the list')
                  . '"><img src="'
                    . $this->urlService()->getRootUrl() . $icon_dir . '/remove_s.png'
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
        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('set_status_header', $code, $text);
    }

    /**
     * Returns the category comment for rendering in html textual mode
     * (subcatify). This method is itself the default handler for the real
     * `render_category_literal_description` plugin hook.
     */
    public function renderCategoryLiteralDescription(RenderCategoryLiteralDescription $event): RenderCategoryLiteralDescription
    {
        $desc = $event->description ?? '';

        $event->description = strip_tags($desc, '<span><p><a><br><b><i><small><big><strong><em>');

        return $event;
    }

    /**
     * Add known menubar blocks.
     * This method is called by a dispatchNotify().
     */
    public function registerDefaultMenubarBlocks(BlockManagerRegisterBlocks $event): void
    {
        $menu = $event->menu;
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
     * Cross-domain generic-row-reader rationale, same as
     * getCatDisplayName() above -- called across image/comment/tag rows
     * from many different modules, only 'name'/'file' read defensively.
     *
     * @param array<string, mixed> $info at least file or name
     */
    #[\Override]
    public function renderElementName(array $info): string
    {
        if (isset($info['name']) && is_string($info['name']) && $info['name'] !== '') {
            $nameEvent = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new RenderElementName($info['name'], $info));

            return $nameEvent->elementName;
        }
        $filename = $info['file'] ?? null;

        return \Piwigo\Core\StringHelper::getNameFromFile(is_string($filename) ? $filename : '');
    }

    /**
     * Returns display description for an element.
     *
     * Same cross-domain generic-row-reader rationale as
     * renderElementName() above.
     *
     * @param array<string, mixed> $info at least comment
     * @param string $param used to identify the trigger
     */
    #[\Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        if (isset($info['comment']) && is_string($info['comment']) && $info['comment'] !== '') {
            $descEvent = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new RenderElementDescription($info['comment'], $param));

            return $descEvent->elementDescription;
        }

        return '';
    }

    /**
     * Add info to the title of the thumbnail based on photo properties.
     *
     * Same cross-domain generic-row-reader rationale as
     * renderElementName() above.
     *
     * @param array<string, mixed> $info hit, rating_score, nb_comments
     */
    #[\Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {

        $details = [];

        if (isset($info['hit']) && is_numeric($info['hit']) && (int) $info['hit'] !== 0) {
            $details[] = Lang::t('%d visits', $info['hit']);
        }

        if (\Piwigo\Config\CurrentConfig::rateEnabled() and isset($info['rating_score']) && is_numeric($info['rating_score']) && (float) $info['rating_score'] !== 0.0) {
            $details[] = Lang::t('rating score %s', $info['rating_score']);
        }

        if (isset($info['nb_comments']) and is_numeric($info['nb_comments']) and (int) $info['nb_comments'] !== 0) {
            $details[] = Translator::get()->plural('%d comment', '%d comments', (int) $info['nb_comments']);
        }

        if (count($details) > 0) {
            $title .= ' (' . implode(', ', $details) . ')';
        }

        if ($comment !== '') {
            $comment = strip_tags($comment);
            $title .= ' ' . substr($comment, 0, 100) . (strlen($comment) > 100 ? '...' : '');
        }

        $title = htmlspecialchars(strip_tags($title));
        $titleEvent = \Piwigo\PluginConfig\EventDispatcher::get()->dispatchChange(new GetThumbnailTitle($title, $info));

        return $titleEvent->title;
    }

    /**
     * Event handler to protect src image urls.
     */
    public function getSrcImageUrlProtectionHandler(string $url, SrcImage $srcImage): string
    {
        return $this->urlService()
            ->getActionUrl($srcImage->id, $srcImage->is_original() ? 'e' : 'r', false);
    }

    /**
     * Event handler to protect element urls.
     *
     * Same cross-domain generic-row-reader rationale as
     * renderElementName() above (matches SrcImage::__construct()'s own
     * shape for this 'id'/'path' pair).
     *
     * @param array<string, mixed> $infos id, path
     */
    public function getElementUrlProtectionHandler(string $url, array $infos): string
    {
        if (\Piwigo\Config\CurrentConfig::originalUrlProtection() === 'images') { // protect only images and not other file types (for example large movies that we don't want to send through our file proxy)
            $path = $infos['path'] ?? null;
            $ext = \Piwigo\Core\StringHelper::getExtension(is_string($path) ? $path : null);
            $picture_ext = \Piwigo\Config\CurrentConfig::pictureExtensions();
            if (! in_array($ext, $picture_ext, true)) {
                return $url;
            }
        }
        $id = $infos['id'] ?? '';
        $id = is_int($id) || is_string($id) ? $id : '';

        return $this->urlService()
            ->getActionUrl($id, 'e', false);
    }

    /**
     * Sends to the template all messages stored in PageState and in the
     * session.
     */
    public function flushPageMessages(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();
        if ($template->get_template_vars('page_refresh') === null) {
            $pageState = \Piwigo\Core\PageState::current();
            $this->flushMessageMode('errors', $pageState->errors, $template);
            $this->flushMessageMode('infos', $pageState->infos, $template);
            $this->flushMessageMode('warnings', $pageState->warnings, $template);
            $this->flushMessageMode('messages', $pageState->messages, $template);
        }
    }

    /**
     * Sends a controller-local, field-keyed error map (e.g.
     * ['login_page_error' => '...'], read by specific key in
     * identification.tpl/register.tpl/password.tpl) to the template --
     * a different shape than PageState::$errors' plain list<string>, so it
     * doesn't live on PageState (see IdentificationController/
     * RegisterController/PasswordController). Merges with the same
     * $_SESSION['page_errors'] flash channel as flushPageMessages(), so
     * calling both in the same request is safe.
     *
     * $keyedErrors' values are genuinely arbitrary by design -- each
     * controller (IdentificationController/RegisterController/
     * PasswordController) defines its own field-keyed error messages.
     *
     * @param array<string, mixed> $keyedErrors
     */
    public function flushKeyedErrors(array $keyedErrors): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();
        if ($template->get_template_vars('page_refresh') === null) {
            $this->flushMessageMode('errors', $keyedErrors, $template);
        }
    }

    /**
     * $messages is either PageState's own list<string> (from
     * flushPageMessages()) or flushKeyedErrors()'s own arbitrary-valued
     * keyed bag -- genuinely dual-shaped, not narrowable to one.
     *
     * @param array<int|string, mixed> $messages
     */
    private function flushMessageMode(string $mode, array $messages, Template $template): void
    {
        // Every writer of $_SESSION['page_*'] elsewhere in the codebase
        // (comments.php, picture.php, admin/batch_manager*.php, ...)
        // guards with is_array() before appending, so this mirrors that
        // same invariant instead of trusting the superglobal's mixed
        // element type.
        if (isset($_SESSION['page_' . $mode]) and is_array($_SESSION['page_' . $mode])) {
            $messages = array_merge($messages, $_SESSION['page_' . $mode]);
            unset($_SESSION['page_' . $mode]);
        }

        if ($messages !== []) {
            $template->assign($mode, $messages);
        }
    }

    /**
     * pwgNl2br is useful for PHP 5.2 which doesn't accept more than 1
     * parameter on nl2br() (and anyway the second parameter of nl2br does not
     * match what Piwigo gives. Registered as an EventDispatcher handler
     * (render_comment_content/render_category_description) -- $string is
     * genuinely arbitrary by design, matching EventDispatcher's own
     * $data contract; the array<int|string, mixed> branch is an identity
     * passthrough, never itself narrowed.
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
