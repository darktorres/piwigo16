<?php

declare(strict_types=1);

namespace Piwigo\Html;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Event\RenderCategoryLiteralDescription;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Category\Projection\CategoryIdNamePermalink;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\FilterState;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\HttpStatusLine;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Core\PageState;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\Event\RenderCommentContent;
use Piwigo\Html\Event\RenderElementDescription;
use Piwigo\Html\Event\RenderElementName;
use Piwigo\Html\Projection\PageMessagesContext;
use Piwigo\Http\ResponseFactory;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\Event\GetSrcImageUrl;
use Piwigo\Lang\Translator;
use Piwigo\Menu\Event\BlockManagerRegisterBlocks;
use Piwigo\Menu\RegisteredBlock;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Picture\Event\GetElementUrl;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Users\CurrentUser;

/**
 * HTML rendering helpers, error pages, and status/header utilities.
 *
 * Constructor collaborators are CurrentConfig, EventDispatcher,
 * ProcessCache, ErrorCollector, CurrentUser, CurrentTemplate, PageState,
 * Translator, CategoryRepository (getCatDisplayNameCache()'s own need),
 * and EntityManagerInterface (getCatDisplayNameFromId()'s own throwaway
 * CategoryService construction) -- there's really only one real
 * `new HtmlService(...)` construction site outside this class's
 * own container-resolved production path
 * (Admin\Extensions\ExtensionScanner.php) and one shared test factory
 * (Tests\Support\HtmlServiceTestFactory), so requiring these two more
 * collaborators doesn't meaningfully widen the blast radius.
 *
 * `Lang` is NOT a required constructor param, despite being a shim this
 * class reads -- it stays a lazily-resolved private helper below (same
 * shape as urlService()), because it's a genuine PHP-DI circular
 * dependency, not just churn: `Lang` itself requires
 * `HtmlRenderingInterface` (this class implements it, so
 * `HtmlService -> Lang -> HtmlRenderingInterface -> HtmlService` would be
 * a hard autowiring cycle). `AccessControl` has the same problem; the one
 * thing this class actually reads from it (`isAGuest()`) is read via
 * `AccessLevelChecker` instead -- that class has no
 * `HtmlRenderingInterface` dependency of its own, so it's built from this
 * class's own already-required `currentUser`/`currentConfig` instead of a
 * container resolve. `UrlService` itself also requires
 * `HtmlRenderingInterface` (this class is its own `htmlRenderer`), and
 * `UrlServiceInterface` is one of the most widely autowired dependencies
 * in the whole app, so HtmlService sits on an unusually broad transitive
 * dependency chain -- a required constructor param here for anything
 * that isn't provably side-effect-free at first resolution risks an
 * eager-resolution regression elsewhere in the app (e.g. a DB-touching
 * factory breaking public/install.php merely by being resolved). Every
 * one of the required collaborators above is individually checked
 * against config/container.php for a custom factory binding with a real
 * side effect -- none has one, all are plain autowired classes.
 *
 * accessDenied()/badRequest()/pageNotFound()/pageForbidden() take
 * Piwigo\Core\RedirectServiceInterface as a required *method* parameter
 * instead, rather than a constructor dependency -- Piwigo\Bootstrap\
 * RedirectService is L4Integration, and this class (L3Presentation) may
 * not depend on it directly per deptrac.yaml's ruleset; the DI
 * container's service-locator accessor on Piwigo\Core\Kernel (the only
 * other way to reach a concrete L4 instance from here without a
 * constructor dependency) is arch-test-restricted to Bootstrap/ and
 * index.php. Every real caller already holds (or can trivially
 * construct) a RedirectServiceInterface instance of its own to pass
 * through.
 *
 * The remaining Url-family calls go through the private urlService()
 * helper below -- a container resolve, not a constructor property:
 * `UrlService` requires `HtmlRenderingInterface` (this class implements
 * it), so a constructor-injected `UrlServiceInterface` here would close a
 * real cycle (`UrlService -> HtmlRenderingInterface -> HtmlService ->
 * UrlServiceInterface -> UrlService`). PHP-DI's reflection-based
 * autowiring only ever inspects class constructors, never ordinary
 * methods, so a private helper method sidesteps that -- unlike an
 * optional/nullable constructor property of the same type, which PHP-DI
 * may still attempt to autowire. Resolves the container-shared instance,
 * not a throwaway `new UrlService($this)`, so `RootPathOverride`'s own
 * cross-instance sharing requirement holds (see that class's own
 * docblock) -- it carries no per-instance state.
 *
 * Implements HtmlRenderingInterface so L1/L2a/L2b classes that can't
 * depend on this L3Presentation class directly can depend on that
 * interface instead -- see its own docblock.
 */
final readonly class HtmlService implements HtmlRenderingInterface
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private EventDispatcher $eventDispatcher,
        private ProcessCache $processCache,
        private ErrorCollector $errorCollector,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private PageState $pageState,
        private Translator $translator,
        private CategoryRepository $categoryRepo,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Container resolve, not a constructor property -- Lang's own
     * constructor requires HtmlRenderingInterface (this class implements
     * it), so a required param here would close a real cycle
     * (`HtmlService -> Lang -> HtmlRenderingInterface -> HtmlService`).
     * Same shape as urlService() below.
     */
    private function lang(): Lang
    {
        $lang = Kernel::container()->get(Lang::class);
        if (! $lang instanceof Lang) {
            throw new LogicException('Container returned an unexpected type for ' . Lang::class);
        }

        return $lang;
    }

    /**
     * Used only inside this class's own one `new PermissionService(...)`
     * construction below. Falls back to a fresh, uninitialised instance
     * when `Kernel::boot()` hasn't run -- that PermissionService is never
     * actually read back out on getCatDisplayNameFromId()'s own call
     * path.
     */
    private function filterState(): FilterState
    {
        if (Kernel::isBooted()) {
            $filterState = Kernel::container()->get(FilterState::class);
            if (! $filterState instanceof FilterState) {
                throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
            }

            return $filterState;
        }

        return new FilterState();
    }

    /**
     * Built from this class's own already-required currentUser/
     * currentConfig -- AccessLevelChecker has no HtmlRenderingInterface
     * dependency of its own, so this needs no container resolve at all.
     */
    private function accessLevelChecker(): AccessLevelChecker
    {
        return new AccessLevelChecker($this->currentUser, $this->currentConfig);
    }

    private function urlService(): UrlServiceInterface
    {
        $urlService = Kernel::container()->get(UrlServiceInterface::class);
        if (! $urlService instanceof UrlServiceInterface) {
            throw new LogicException('Container returned an unexpected type for ' . UrlServiceInterface::class);
        }

        return $urlService;
    }

    /**
     * Generates breadcrumb from categories list.
     * Categories string returned contains categories as given in the input
     * array $catInformations. $catInformations array must be an array
     * of array(id=>?, name=>?, permalink=>?). If url input parameter is
     * null, returns only the categories name without links.
     *
     * Cross-domain generic-row-reader rationale, same as
     * Category\CategoryService::compareByGlobalRank() -- per
     * all 3 real call sites: CategoryService::getCategoryInfo()'s
     * own 'upper_names' (clean id/name/permalink), a raw `SELECT id, name,
     * permalink` query (name/permalink nullable there), and
     * GalleryController's own qsearch 'matching_cats' rows (Search
     * module's QResults::$all_cats, already mixed via EventDispatcher).
     *
     * @param array<int, array<string, mixed>> $catInformations
     */
    #[Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        $level_separator = $this->currentConfig->levelSeparator;

        $output = '';
        $is_first = true;

        foreach ($catInformations as $cat) {
            $nameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName(is_string($cat['name']) ? $cat['name'] : '', 'get_cat_display_name'));
            $cat['name'] = $nameEvent->categoryName;
            // Escaped only for the HTML text this method builds below --
            // $cat['name'] itself stays raw for makeIndexUrl()'s own
            // 'id-name'-style permalink slug generation (P44-D).
            $cat_name_html = htmlspecialchars($cat['name']);

            if ($is_first) {
                $is_first = false;
            } else {
                $output .= $level_separator;
            }

            if (! isset($url)) {
                $output .= $cat_name_html;
            } elseif ($url === '') {
                $output .= '<a href="'
                      . $this->urlService()->makeIndexUrl(
                          [
                              'category' => $cat,
                          ],
                      )
                      . '">';
                $output .= $cat_name_html . '</a>';
            } else {
                $cat_id = is_scalar($cat['id']) ? (string) $cat['id'] : '';
                $output .= '<a href="' . $this->urlService()->getRootUrl() . $url . $cat_id . '">';
                $output .= $cat_name_html . '</a>';
            }
        }

        return $output;
    }

    /**
     * Generates breadcrumb from categories list using a cache.
     * @see getCatDisplayName()
     */
    #[Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        $level_separator = $this->currentConfig->levelSeparator;

        $add_url_params = [];
        if (isset($authKey)) {
            $add_url_params['auth'] = $authKey;
        }

        $cat_names = $this->catNames();

        $output = '';
        if ($singleLink) {
            $uppercats_array = explode(',', $uppercats);
            $last_uppercat = array_pop($uppercats_array);
            $single_url = $this->urlService()
                ->addUrlParams($this->urlService()->getRootUrl() . ($url ?? '') . $last_uppercat, $add_url_params);
            $output .= '<a href="' . $single_url . '"';
            if (isset($linkClass)) {
                $output .= ' class="' . $linkClass . '"';
            }
            $output .= '>';
        }
        $is_first = true;
        foreach ($this->resolveUppercats($uppercats, $cat_names) as $segment) {
            $category_id = $segment['id'];
            $cat = $segment['cat'];
            $cat_name_html = $segment['name'];

            if ($is_first) {
                $is_first = false;
            } else {
                $output .= '<span>' . $level_separator . '</span>';
            }

            if (! isset($url) or $singleLink) {
                $output .= $cat_name_html;
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
                . '">' . $cat_name_html . '</a>';
            } else {
                $output .= '
<a href="' . $this->urlService()->getRootUrl() . $url . $category_id . '">' . $cat_name_html . '</a>';
            }
        }

        if ($singleLink) {
            $output .= '</a>';
        }

        return $output;
    }

    /**
     * The same breadcrumb `getCatDisplayNameCache()` renders, as data.
     *
     * Exists so a JSON client can build the identical markup itself.
     * `CategoryListController` used to answer with the rendered HTML
     * (`additional_output=full_name_with_admin_links`) and deliberately
     * stopped; without the per-segment names, though, a client holding only
     * `uppercats` (ids) and `fullname` (the joined text) cannot rebuild the
     * per-segment links at all.
     *
     * `name` is HTML-escaped, the same convention `fullname` follows, so it
     * is interpolated into markup rather than assigned as text.
     *
     * @return list<array{id: string, name: string}>
     */
    #[Override]
    public function getCatBreadcrumb(string $uppercats): array
    {
        $breadcrumb = [];
        foreach ($this->resolveUppercats($uppercats, $this->catNames()) as $segment) {
            $breadcrumb[] = [
                'id' => $segment['id'],
                'name' => $segment['name'],
            ];
        }

        return $breadcrumb;
    }

    /**
     * The id -> name/permalink map both breadcrumb builders read, cached for
     * the request.
     *
     * @return array<mixed>
     */
    private function catNames(): array
    {
        if ($this->processCache->has('cat_names')) {
            $cat_names_raw = $this->processCache->get('cat_names');
        } else {
            $cat_names_raw = $this->categoryRepo->findAllIdNamePermalink();
            $this->processCache->set('cat_names', $cat_names_raw);
        }

        return is_array($cat_names_raw) ? $cat_names_raw : [];
    }

    /**
     * Resolves each id in an `uppercats` chain to its rendered name.
     *
     * Shared by the HTML and the data breadcrumb so the two cannot drift:
     * both must dispatch `RenderCategoryName` (a plugin may rewrite a name)
     * and both must escape identically. `cat` carries the raw name through
     * for `makeIndexUrl()`'s own 'id-name' permalink slug generation (P44-D),
     * which needs it unescaped.
     *
     * @param array<mixed> $cat_names
     * @return list<array{id: string, cat: array<string, mixed>, name: string}>
     */
    private function resolveUppercats(string $uppercats, array $cat_names): array
    {
        $segments = [];
        foreach (explode(',', $uppercats) as $category_id) {
            $cat = $cat_names[$category_id] ?? null;
            $cat = $cat instanceof CategoryIdNamePermalink ? $cat->toArray() : [];

            $cat_name = $cat['name'] ?? null;
            $nameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName(is_string($cat_name) ? $cat_name : '', 'get_cat_display_name_cache'));
            $cat['name'] = $nameEvent->categoryName;

            $segments[] = [
                'id' => $category_id,
                'cat' => $cat,
                'name' => htmlspecialchars($cat['name']),
            ];
        }

        return $segments;
    }

    /**
     * Generates breadcrumb for a category.
     * @see getCatDisplayName()
     */
    public function getCatDisplayNameFromId(int $catId, ?string $url = ''): string
    {
        // Throwaway CategoryService construction, not constructor injection
        // -- HtmlService can never depend on CategoryService: CategoryService
        // needs UrlServiceInterface, which needs HtmlRenderingInterface,
        // which HtmlService implements -- a real cycle. Reuses this class's
        // own categoryRepo/entityManager rather than building a second
        // repository/EntityManager.
        $cat_info = new CategoryService(
            $this->lang(),
            $this->categoryRepo,
            new PermissionService(
                new PermissionRepository($this->entityManager),
                TypedRepository::narrow($this->entityManager->getRepository(GroupEntity::class), GroupRepository::class),
                new CategoryRepository($this->entityManager, $this->currentConfig),
                $this->currentUser,
                $this->filterState(),
                $this->accessLevelChecker()
            ),
            $this->currentConfig,
            $this->eventDispatcher,
            $this->translator,
            $this->accessLevelChecker()
        )->getCategoryInfo($catId);
        // $catId isn't existence-validated by callers (a URL param) -- a
        // stale/forged id falls back to an empty breadcrumb.
        $upper_names = array_map(
            static fn (CategoryIdNamePermalink $row): array => $row->toArray(),
            $cat_info->upperNames ?? []
        );
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
     * (`Picture\PictureCommentRenderer`, `Controller\CommentsController`,
     * `Controller\Api\Comments\CommentListController`) reaches it through
     * `dispatch(new RenderCommentContent(...))`, not directly.
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
    #[Override]
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
    #[Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        $name_a = is_string($a['name'] ?? null) ? $a['name'] : '';
        $name_b = is_string($b['name'] ?? null) ? $b['name'] : '';

        // Narrowed once here (fix pattern #7): ProcessCache::get() returns
        // mixed, so the stored value is still mixed even after this check.
        $transliterated_raw = $this->processCache->get(self::class . '::tagAlphaCompare');
        $transliterated = is_array($transliterated_raw) ? $transliterated_raw : [];

        foreach ([$name_a, $name_b] as $tag_name) {
            // pwg_transliterate() always returns string, so a cached entry that
            // isn't a string was never written by this loop and must be
            // (re)computed -- a real runtime guard equivalent to the original
            // isset() check (fix pattern #6).
            if (! is_string($transliterated[$tag_name] ?? null)) {
                $transliterated[$tag_name] = StringHelper::pwgTransliterate($tag_name);
            }
        }

        $this->processCache->set(self::class . '::tagAlphaCompare', $transliterated);

        $translit_a = is_string($transliterated[$name_a] ?? null) ? $transliterated[$name_a] : StringHelper::pwgTransliterate($name_a);
        $translit_b = is_string($transliterated[$name_b] ?? null) ? $transliterated[$name_b] : StringHelper::pwgTransliterate($name_b);

        return strcmp($translit_a, $translit_b);
    }

    /**
     * Throws Piwigo\Http\ResponseReadyException (a 401 page or a redirect
     * to the login page) instead of exiting directly -- see that
     * exception class's own docblock for why and where it's caught.
     */
    #[Override]
    public function accessDenied(RedirectServiceInterface $redirectService): never
    {
        if ($this->currentUser->isInitialized() and ! $this->accessLevelChecker()->isAGuest()) {
            $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<link rel="shortcut icon" type="image/x-icon" href="themes/default/icon/favicon.ico">
<div style="display: flex; justify-content: center;align-items: center;height: 100vh;margin: 0;color: #3C3C3C;font-family: \'Open Sans\', sans-serif;font-size: 20px;font-style: normal;font-weight: 600;line-height: normal;">
  <div style="text-align:center;">
    <img src="themes/default/icon/warning-triangle.svg" alt="warning-triangle" >
    <p style="max-width: 400px; margin-top 20px;">' . $this->lang()->t('You are not authorized to access the requested page') . '</p>
    <a href="' . $this->urlService()->makeIndexUrl() . '" style="display: inline-block;padding: 10px 20px;margin: 10px;margin-top: 50px;border-radius: 7px;cursor: pointer;width: 150px;background-color: #F77000;color: #fff;text-decoration: none;border: 2px solid #F77000;">' . $this->lang()->t('Home') . '</a>
  </div>
</div>';
            throw new ResponseReadyException(ResponseFactory::html($html, 401));
        }

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $request_uri = is_string($request_uri) ? $request_uri : '';
        $redirectService->redirectHttp($this->urlService()->getRootUrl() . 'identification.php?redirect=' . urlencode(urlencode($request_uri)));
    }

    /**
     * redirectHtml()'s own $status param carries the 403 through to the
     * built Response instead of a separate setStatusHeader() call.
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
<h1 style="text-align:left; font-size:36px;">' . $this->lang()->t('Forbidden') . '</h1><br>'
. $msg . '</div>',
            5,
            403,
        );
    }

    /**
     * redirectHtml()'s own $status param carries the 400 through to the
     * built Response instead of a separate setStatusHeader() call.
     * @todo nice display if $template loaded
     */
    #[Override]
    public function badRequest(RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        if ($alternateUrl === null) {
            $alternateUrl = $this->urlService()
                ->makeIndexUrl();
        }
        $redirectService->redirectHtml(
            $alternateUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . $this->lang()->t('Bad request') . '</h1><br>'
. $msg . '</div>',
            5,
            400,
        );
    }

    /**
     * redirectHtml()'s own $status param carries the 404 through to the
     * built Response instead of a separate setStatusHeader() call.
     * @todo nice display if $template loaded
     *
     * @param string|null $msg null is treated the same as '' below (string
     *   concatenation); comments.php passes null when comments are disabled
     */
    #[Override]
    public function pageNotFound(RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        if ($alternateUrl === null) {
            $alternateUrl = $this->urlService()
                ->makeIndexUrl();
        }
        $redirectService->redirectHtml(
            $alternateUrl,
            '<div style="text-align:left; margin-left:5em;margin-bottom:5em;">
<h1 style="text-align:left; font-size:36px;">' . $this->lang()->t('Page not found') . '</h1><br>'
. ($msg ?? '') . '</div>',
            5,
            404,
        );
    }

    /**
     * Throws Piwigo\Http\ResponseReadyException (a 500 page) instead of
     * exiting directly, after still logging via
     * ErrorCollector::recordFatal() -- see this method's own body for the
     * full reasoning.
     * @todo nice display if $template loaded
     */
    #[Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        if ($title === null || $title === '') {
            $title = $this->lang()
                ->t('Piwigo encountered a non recoverable error');
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

        // PHP 8.4 deprecates passing E_USER_ERROR to trigger_error() at
        // all, so this always logs via ErrorCollector::recordFatal() and
        // throws ResponseReadyException with the 500 page below instead.
        //
        // Never falls back to exit() when ErrorCollector isn't active:
        // ErrorCollector::installIfConfigured() only installs when the
        // deployment policy's showPhpErrorsOnFrontend is *also* true -- a
        // real, valid config (showPhpErrors on, showPhpErrorsOnFrontend
        // off) leaves isActive() false on every request, where
        // hard-exiting instead of returning the real 500 page below would
        // be a genuine regression. Exiting would also bypass this
        // codebase's test pattern (e.g. PageAssetsTest.php/
        // TemplateInstanceTest.php) of installing a throwaway
        // set_error_handler() around a fatalError()-reaching call and
        // asserting on what it captured -- exit() bypasses every handler
        // unconditionally, killing the test process instead. Always
        // recording (regardless of isActive()) and always falling through
        // to the real error page below is both simpler and correct for
        // every one of these cases.
        $this->errorCollector->recordFatal(strip_tags($msg) . $btrace_msg);

        throw new ResponseReadyException(ResponseFactory::html($display, 500));
    }

    /**
     * Returns the breadcrumb to be displayed above thumbnails on tag page.
     *
     * $tags is an explicit param instead of `global $page['tags']` -- the
     * one real caller (SectionPopulator::populate()) calls this from
     * within its own execution, before any SectionContext exists yet to
     * read via SectionContextRegistry (an "in-flight collaborator", same
     * shape as CalendarRenderer/SearchFilterRenderer's own params).
     *
     * @param list<array<string, mixed>> $tags
     */
    #[Override]
    public function getTagsContentTitle(array $tags): string
    {
        return '<a href="' . $this->urlService()->getRootUrl() . 'tags.php" title="' . $this->lang()->t('display available tags') . '">'
          . $this->lang()->t(count($tags) > 1 ? 'Tags' : 'Tag')
          . '</a> ';
    }

    /**
     * Returns the breadcrumb to be displayed above thumbnails on combined
     * categories page.
     *
     * $category/$combinedCategories are explicit params instead of
     * `global $page['category']`/`['combined_categories']` -- same
     * in-flight-collaborator reasoning as getTagsContentTitle() above.
     *
     * @param array<string, mixed>|null $category
     * @param list<array<string, mixed>> $combinedCategories
     */
    #[Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        $title = $this->lang()
            ->t('Albums') . ' ';

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

                // This class is L3Presentation, so it may read the
                // request's Template instance (also L3) directly, no
                // ThemeConfProviderInterface indirection needed (unlike
                // SrcImage, L2a). CurrentTemplate is always initialized on
                // any request that renders this markup, but this stays
                // defensive.
                $request_template = $this->currentTemplate->isInitialized() ? $this->currentTemplate->get() : null;
                $icon_dir = $request_template instanceof Template ? $request_template->themeConf('icon_dir') : '';

                $title .=
                  '<a id="TagsGroupRemoveTag" href="' . $remove_url . '" style="border:none;" title="'
                  . $this->lang()->t('remove this tag from the list')
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
     * Sets the http status header (200,401,...). Returns the resolved
     * HttpStatusLine (code + the reason phrase actually sent) rather than
     * void -- header() itself is a no-op under CLI SAPI with no way to
     * inspect what it received after the fact, so this is how a caller or
     * test observes the computed line.
     */
    #[Override]
    public function setStatusHeader(int $code, string $text = ''): HttpStatusLine
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

        return new HttpStatusLine($code, $text);
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
     * This method is called by a fire-and-forget dispatch().
     */
    public function registerDefaultMenubarBlocks(BlockManagerRegisterBlocks $event): void
    {
        $menu = $event->menu;
        if ($menu->getId() !== 'menubar') {
            return;
        }
        $menu->registerBlock(new RegisteredBlock('mbLinks', 'Links', 'piwigo'));
        $menu->registerBlock(new RegisteredBlock('mbCategories', 'Albums', 'piwigo'));
        $menu->registerBlock(new RegisteredBlock('mbTags', 'Tags', 'piwigo'));
        $menu->registerBlock(new RegisteredBlock('mbSpecials', 'Specials', 'piwigo'));
        $menu->registerBlock(new RegisteredBlock('mbMenu', 'Menu', 'piwigo'));
        $menu->registerBlock(new RegisteredBlock('mbRelatedCategories', 'Related albums', 'piwigo'));

        // We hide the quick identification menu on the identification page. It
        // would be confusing.
        if (PageFilterHelper::scriptBasename($this->currentConfig) !== 'identification') {
            $menu->registerBlock(new RegisteredBlock('mbIdentification', 'Identification', 'piwigo'));
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
    #[Override]
    public function renderElementName(array $info): string
    {
        if (isset($info['name']) && is_string($info['name']) && $info['name'] !== '') {
            $nameEvent = $this->eventDispatcher->dispatch(new RenderElementName($info['name'], $info));

            return $nameEvent->elementName;
        }
        $filename = $info['file'] ?? null;

        return StringHelper::getNameFromFile(is_string($filename) ? $filename : '');
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
    #[Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        if (isset($info['comment']) && is_string($info['comment']) && $info['comment'] !== '') {
            $descEvent = $this->eventDispatcher->dispatch(new RenderElementDescription($info['comment'], $param));

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
    #[Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {

        $details = [];

        if (isset($info['hit']) && is_numeric($info['hit']) && (int) $info['hit'] !== 0) {
            $details[] = $this->lang()->t('%d visits', $info['hit']);
        }

        if ($this->currentConfig->rateEnabled and isset($info['rating_score']) && is_numeric($info['rating_score']) && (float) $info['rating_score'] !== 0.0) {
            $details[] = $this->lang()->t('rating score %s', $info['rating_score']);
        }

        if (isset($info['nb_comments']) and is_numeric($info['nb_comments']) and (int) $info['nb_comments'] !== 0) {
            $details[] = $this->translator->plural('%d comment', '%d comments', (int) $info['nb_comments']);
        }

        if (count($details) > 0) {
            $title .= ' (' . implode(', ', $details) . ')';
        }

        if ($comment !== '') {
            $comment = strip_tags($comment);
            $title .= ' ' . substr($comment, 0, 100) . (strlen($comment) > 100 ? '...' : '');
        }

        $title = strip_tags($title);

        return $title;
    }

    /**
     * Event handler to protect src image urls.
     */
    public function getSrcImageUrlProtectionHandler(GetSrcImageUrl $event): GetSrcImageUrl
    {
        $event->url = $this->urlService()
            ->getActionUrl($event->value->id, $event->value->isOriginal() ? 'e' : 'r', false);

        return $event;
    }

    /**
     * Event handler to protect element urls.
     *
     * Same cross-domain generic-row-reader rationale as
     * renderElementName() above (matches SrcImage::__construct()'s own
     * shape for this 'id'/'path' pair).
     */
    public function getElementUrlProtectionHandler(GetElementUrl $event): GetElementUrl
    {
        $infos = $event->elementInfo;
        if ($this->currentConfig->originalUrlProtection === 'images') { // protect only images and not other file types (for example large movies that we don't want to send through our file proxy)
            $path = $infos['path'] ?? null;
            $ext = StringHelper::getExtension(is_string($path) ? $path : null);
            $picture_ext = $this->currentConfig->pictureExtensions;
            if (! in_array($ext, $picture_ext, true)) {
                return $event;
            }
        }
        $id = $infos['id'] ?? '';
        $id = is_int($id) || is_string($id) ? $id : '';

        $event->url = $this->urlService()
            ->getActionUrl($id, 'e', false);

        return $event;
    }

    /**
     * Sends to the template all messages stored in PageState and in the
     * session.
     */
    public function flushPageMessages(): void
    {
        $template = $this->currentTemplate->get();
        if ($template->getTemplateVars('page_refresh') === null) {
            $pageState = $this->pageState;
            $template->assignContext(new PageMessagesContext(
                errors: $this->flushMessageMode('errors', $pageState->errors),
                infos: $this->flushMessageMode('infos', $pageState->infos),
                warnings: $this->flushMessageMode('warnings', $pageState->warnings),
                messages: $this->flushMessageMode('messages', $pageState->messages),
            ));
        }
    }

    /**
     * Sends a controller-local, string-keyed error map (e.g.
     * ['login_page_error' => '...']) to the template -- a different
     * shape than PageState::$errors' plain list<string>, so it doesn't
     * live on PageState (see IdentificationController/RegisterController/
     * PasswordController). Merges with the same $_SESSION['page_errors']
     * flash channel as flushPageMessages(), so calling both in the same
     * request is safe.
     *
     * $keyedErrors' own keys exist so each controller can overwrite its
     * own named error slot (e.g. re-assigning 'login_page_error' rather
     * than accumulating duplicates) -- no template anywhere reads
     * `$errors` by specific key; every real consumer
     * (`infos_errors.latte`'s own `{foreach $errors as $error}`)
     * reads values only, so the keys never actually reach the rendered
     * page. Every real value across every real call site
     * (Identification/Register/PasswordController) is a translated
     * string, not genuinely arbitrary.
     *
     * @param array<string, string> $keyedErrors
     */
    public function flushKeyedErrors(array $keyedErrors): void
    {
        $template = $this->currentTemplate->get();
        if ($template->getTemplateVars('page_refresh') === null) {
            $template->assignContext(new PageMessagesContext(
                errors: $this->flushMessageMode('errors', $keyedErrors),
                infos: null,
                warnings: null,
                messages: null,
            ));
        }
    }

    /**
     * $messages is either PageState's own list<string> (from
     * flushPageMessages()) or flushKeyedErrors()'s own string-keyed error
     * bag -- genuinely array<array-key, string> either way (per
     * every real PageState field declaration and every real
     * flushKeyedErrors() call site), not a vague mixed value
     * type. $_SESSION['page_*'] is likewise always a plain list<string>
     * in practice -- every real writer elsewhere in the codebase
     * (comments.php, picture.php, admin/batch_manager*.php, ...) guards
     * with is_array() before pushing a translated string -- but it still
     * crosses a superglobal boundary PHPStan can't see through, so its
     * elements are narrowed defensively here (array_filter(is_string()))
     * rather than trusted.
     *
     * @param array<array-key, string> $messages
     * @return array<array-key, string>|null
     */
    private function flushMessageMode(string $mode, array $messages): ?array
    {
        $session_key = 'page_' . $mode;
        if (isset($_SESSION[$session_key]) and is_array($_SESSION[$session_key])) {
            $messages = array_merge($messages, array_filter($_SESSION[$session_key], is_string(...)));
            unset($_SESSION[$session_key]);
        }

        return $messages !== [] ? $messages : null;
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
