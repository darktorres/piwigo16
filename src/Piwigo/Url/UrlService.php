<?php

declare(strict_types=1);

namespace Piwigo\Url;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\CookieService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\Projection\CategoryInfo;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ApiContext;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\NoMatchSentinel;
use Piwigo\Group\GroupEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;

/**
 * URL building/parsing for the gallery's own routes (index/picture/action
 * sections, permalinks, chronology/start/flat params).
 *
 * `cookie_path()`, `find_tags()` (`Piwigo\Tag\TagService::findTags()`),
 * `get_cat_info()`/`get_cat_id_from_permalinks()`
 * (`Piwigo\Category\CategoryService`) are called directly (not
 * free-function delegates) -- Auth\CookieService/Tag/Category are all
 * same-layer-or-below (L2b may depend on L2a). `bad_request()`/
 * `page_not_found()`/`fatal_error()` route through the
 * constructor-injected HtmlRenderingInterface (Piwigo\Core), the same
 * pattern used by CategoryService/AuthService/CommentService/UserService.
 * parseSectionUrl() alone reaches badRequest()/pageNotFound() (both take
 * a required RedirectServiceInterface parameter -- see
 * HtmlRenderingInterface's own docblock); RedirectServiceInterface is
 * passed into that one method as a parameter rather than held as a
 * constructor property, since HtmlService's own construction of
 * UrlService would otherwise need a concrete L4 Piwigo\Bootstrap\
 * RedirectService, which HtmlService (L3Presentation) may not depend on
 * directly per deptrac.yaml's ruleset.
 *
 * AccessLevelChecker has no UrlServiceInterface dependency of its own, so
 * it's built directly from this class's own currentUser/currentConfig
 * rather than resolved from the container.
 */
final readonly class UrlService implements UrlServiceInterface
{
    public function __construct(
        private HtmlRenderingInterface $htmlRenderer,
        private RootPathOverride $rootPathOverride,
        private SectionContextRegistry $sectionContextRegistry,
        private RequestMountDepth $requestMountDepth,
        private CurrentConfig $currentConfig,
        private DeploymentPolicy $deploymentPolicy,
        private ApiContext $apiContext,
        private CurrentUser $currentUser,
        private Lang $lang,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Built from this class's own already-required currentUser/
     * currentConfig -- AccessLevelChecker has no UrlServiceInterface
     * dependency of its own, so this needs no container resolve at all.
     */
    private function accessLevelChecker(): AccessLevelChecker
    {
        return new AccessLevelChecker($this->currentUser, $this->currentConfig);
    }

    /**
     * Container resolve, not a constructor property -- the only real use
     * in this class is the one `new TagService(...)` construction below. A
     * required constructor param here would ripple to every real
     * `new UrlService(...)` construction site across the app (still
     * manually `new`'d at dozens of sites) for a single caller.
     */
    private function currentLogger(): CurrentLogger
    {
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }

        return $currentLogger;
    }

    /**
     * Same reasoning as currentLogger() above.
     */
    private function sessionService(): SessionService
    {
        $sessionService = Kernel::container()->get(SessionService::class);
        if (! $sessionService instanceof SessionService) {
            throw new LogicException('Container returned an unexpected type for ' . SessionService::class);
        }

        return $sessionService;
    }

    /**
     * Same reasoning as currentLogger()/sessionService() above -- used only
     * inside the one `new CategoryService(...)` construction below.
     */
    private function translator(): Translator
    {
        $translator = Kernel::container()->get(Translator::class);
        if (! $translator instanceof Translator) {
            throw new LogicException('Container returned an unexpected type for ' . Translator::class);
        }

        return $translator;
    }

    /**
     * Same reasoning as currentLogger()/sessionService()/translator()
     * above -- used only to satisfy the throwaway `new ImageService(...)`
     * construction below (TagService's own ImageService collaborator is
     * never actually read on this findTags() call path).
     */
    private function paths(): Paths
    {
        $paths = Kernel::container()->get(Paths::class);
        if (! $paths instanceof Paths) {
            throw new LogicException('Container returned an unexpected type for ' . Paths::class);
        }

        return $paths;
    }

    /**
     * Same reasoning as currentLogger()/sessionService()/translator()
     * above -- used only inside this class's own permissionService()
     * resolver below. Falls back to a fresh, uninitialised instance when
     * `Kernel::boot()` hasn't run.
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
     * Built from this class's own already-required/already-resolvable
     * properties -- PermissionRepository/CategoryRepository need only
     * this class's own $entityManager/$currentConfig, GroupRepository is
     * $entityManager's own getRepository() call, CurrentUser is already a
     * constructor property, and FilterState/AccessLevelChecker come from
     * this class's own filterState()/accessLevelChecker() resolvers above
     * -- no container resolve needed. Used at this class's own 3 `new
     * PermissionService(...)` construction sites this replaces
     * (parseSectionUrl()'s categories branch, and twice more inside its
     * tags branch's nested TagService/ImageService/CategoryService
     * construction).
     */
    private function permissionService(): PermissionService
    {
        return new PermissionService(
            new PermissionRepository($this->entityManager),
            $this->entityManager->getRepository(GroupEntity::class),
            new CategoryRepository($this->entityManager, $this->currentConfig),
            $this->currentUser,
            $this->filterState(),
            $this->accessLevelChecker(),
        );
    }

    /**
     * Same "built from this class's own already-resolvable properties"
     * rationale as permissionService() above. Used at this class's own 2
     * `new CategoryService(...)` construction sites this replaces
     * (parseSectionUrl()'s categories branch, and once more inside its
     * tags branch's nested TagService/ImageService construction).
     */
    private function categoryService(): CategoryService
    {
        return new CategoryService(
            $this->lang,
            new CategoryRepository($this->entityManager, $this->currentConfig),
            $this->permissionService(),
            $this->currentConfig,
            $this->eventDispatcher,
            $this->translator(),
            $this->accessLevelChecker(),
            new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig)
        );
    }

    /**
     * Returns a prefix for each url link on displayed page and returns an
     * empty string for current path.
     */
    #[Override]
    public function getRootUrl(): string
    {
        // root_path comes from RootPathOverride when setMakeFullUrl() is
        // active, else SectionContextRegistry::current() -- nullable here
        // (unlike GalleryController/PictureController's own
        // guaranteed-non-null read) since this runs for non-gallery pages
        // too.
        $root_path = $this->rootPathOverride->current() ?? $this->sectionContextRegistry->current()?->rootPath;
        if ($root_path === null || $root_path === '') {
            return str_repeat('../', $this->requestMountDepth->current());
        }

        return $root_path;
    }

    /**
     * Returns the absolute url to the root of PWG.
     *
     * @param bool $withScheme if false - does not add http://toto.com
     */
    #[Override]
    public function getAbsoluteRootUrl(bool $withScheme = true): string
    {
        // Support X-Forwarded-Proto header for HTTPS detection in PHP
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) and $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $_SERVER['HTTPS'] = 'on';
        }

        $url = '';
        if ($withScheme) {
            $is_https = false;
            $https_value = $_SERVER['HTTPS'] ?? null;
            if (is_string($https_value) &&
              ((strtolower($https_value) === 'on') or ($https_value === '1'))) {
                $is_https = true;
                $url .= 'https://';
            } else {
                $url .= 'http://';
            }
            // A configured gallery_url is a canonical, admin-set base URL --
            // its host is trusted outright and never derived from a
            // client-controlled header. [SEC-29]
            $configured_host = $this->configuredHost();

            if ($configured_host !== null) {
                $url .= $configured_host;
            } else {
                $forwarded_host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null;
                if (is_string($forwarded_host)) {
                    $url .= $this->trustedHost($forwarded_host);
                } else {
                    $http_host = $_SERVER['HTTP_HOST'] ?? null;
                    $url .= $this->trustedHost(is_string($http_host) ? $http_host : '');

                    $url_port = null;

                    if ($this->currentConfig->urlPort === 'none') {
                        // do nothing
                    } elseif ($this->currentConfig->urlPort === 'auto') {
                        // Default to 80 (matching 16.x-rewrite's own
                        // UrlService) when SERVER_PORT is genuinely absent --
                        // defaulting to null instead would leave
                        // $server_port !== 80/443 vacuously true, appending a
                        // bare trailing ':' with no port digits after it.
                        $server_port_raw = $_SERVER['SERVER_PORT'] ?? 80;
                        $server_port = is_numeric($server_port_raw) ? (int) $server_port_raw : 80;
                        if ((! $is_https && $server_port !== 80) || ($is_https && $server_port !== 443)) {
                            $url_port = ':' . $server_port;
                        }
                    } else {
                        // we have a custom port
                        $url_port = ':' . $this->currentConfig->urlPort;
                    }

                    if ($url_port !== null and strrchr($url, ':') !== $url_port) {
                        $url .= $url_port;
                    }
                }
            }
        }
        $url .= new CookieService()
            ->cookiePath();

        return $url;
    }

    /**
     * Returns the configured gallery_url's host[:port], or null when
     * unconfigured (auto-detect mode). Reads
     * Piwigo\Config\CurrentConfig::galleryUrl() directly.
     */
    private function configuredHost(): ?string
    {
        $gallery_url = $this->currentConfig->galleryUrl;
        if (! is_string($gallery_url) || $gallery_url === '') {
            return null;
        }

        $host = parse_url($gallery_url, \PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $port = parse_url($gallery_url, \PHP_URL_PORT);

        return is_int($port) ? $host . ':' . $port : $host;
    }

    /**
     * [SEC-29] Host-header poisoning guard: when
     * $this->deploymentPolicy->allowedHosts is
     * non-empty, an unrecognized candidate host is never embedded into an
     * outbound URL -- falls back to the first configured host instead.
     * Empty allow-list means "not configured" -- falls back to trusting
     * the header (auto-detect).
     */
    private function trustedHost(string $candidate): string
    {
        $allowed = $this->deploymentPolicy->allowedHosts;

        if ($allowed === [] || in_array($candidate, $allowed, true)) {
            return $candidate;
        }

        return $allowed[0];
    }

    /**
     * Adds one or more _GET style parameters to an url.
     * example: addUrlParams('/x', ['a'=>'b']) returns /x?a=b
     * addUrlParams('/x?cat_id=10', ['a'=>'b']) returns /x?cat_id=10&amp;a=b
     *
     * @param array<int|string, mixed> $params
     */
    #[Override]
    public function addUrlParams(string $url, array $params, string $argSeparator = '&amp;'): string
    {
        if ($params !== []) {
            if ($this->apiContext->isActive() and $argSeparator === '&amp;') {
                $argSeparator = '&';
            }

            $is_first = true;
            foreach ($params as $param => $val) {
                if ($is_first) {
                    $is_first = false;
                    $url .= (! str_contains($url, '?')) ? '?' : $argSeparator;
                } else {
                    $url .= $argSeparator;
                }
                $url .= $param;
                if (isset($val)) {
                    $url .= '=' . (is_scalar($val) ? (string) $val : '');
                }
            }
        }

        return $url;
    }

    /**
     * Build an index URL for a specific section.
     *
     * @param array<string, mixed> $params
     */
    #[Override]
    public function makeIndexUrl(array $params = []): string
    {
        $url = $this->getRootUrl() . 'index';
        if ($this->currentConfig->phpExtensionInUrls) {
            $url .= '.php';
        }
        if ($this->currentConfig->questionMarkInUrls) {
            $url .= '?';
        }

        $url_before_params = $url;

        $url .= $this->makeSectionInUrl($params);
        $url = $this->addWellKnownParamsInUrl($url, $params);

        if ($url === $url_before_params) {
            $url = $this->getAbsoluteRootUrl($this->urlIsRemote($url));
        }

        return $url;
    }

    /**
     * Build an index URL with current page parameters, but with
     * redefinitions and removes.
     *
     * duplicateIndexUrl([
     *   'category' => ['id'=>12, 'name'=>'toto'],
     * ], ['start']) will create an index URL on the current section
     * (categories), but on a redefined category and without the start URL
     * parameter.
     *
     * @param array<string, mixed> $redefined keys
     * @param array<int, string> $removed keys
     */
    #[Override]
    public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
    {
        return $this->makeIndexUrl(
            $this->paramsForDuplication($redefined, $removed),
        );
    }

    /**
     * Returns the current gallery-navigation-context params (with key
     * redefined and key removed) used to duplicate the current URL.
     *
     * Sourced from SectionContextRegistry::current()->toUrlParams() --
     * nullable-safe (falls back to an empty params array) since this runs
     * for non-gallery pages too. root_path is overridden the same way
     * getRootUrl() is, so a duplicated URL still gets the absolute path
     * while setMakeFullUrl() is active.
     *
     * @param array<string, mixed> $redefined keys
     * @param array<int, string> $removed keys
     * @return array<string, mixed>
     */
    public function paramsForDuplication(array $redefined, array $removed): array
    {
        $params = $this->sectionContextRegistry->current()?->toUrlParams() ?? [];

        $rootPathOverride = $this->rootPathOverride->current();
        if ($rootPathOverride !== null) {
            $params['root_path'] = $rootPathOverride;
        }

        foreach ($removed as $param_key) {
            unset($params[$param_key]);
        }

        foreach ($redefined as $redefined_param => $redefined_value) {
            $params[$redefined_param] = $redefined_value;
        }

        return $params;
    }

    /**
     * Create a picture URL with current page parameters, but with
     * redefinitions and removes. See duplicateIndexUrl().
     *
     * @param array<string, mixed> $redefined keys
     * @param array<int, string> $removed keys
     */
    #[Override]
    public function duplicatePictureUrl(array $redefined = [], array $removed = []): string
    {
        return $this->makePictureUrl(
            $this->paramsForDuplication($redefined, $removed),
        );
    }

    /**
     * Create a picture URL on a specific section for a specific picture.
     *
     * @param array<string, mixed> $params
     */
    #[Override]
    public function makePictureUrl(array $params): string
    {
        $url = $this->getRootUrl() . 'picture';
        if ($this->currentConfig->phpExtensionInUrls) {
            $url .= '.php';
        }
        if ($this->currentConfig->questionMarkInUrls) {
            $url .= '?';
        }
        $url .= '/';
        $image_id = $params['image_id'] ?? null;
        $picture_url_style = $this->currentConfig->pictureUrlStyle;
        switch ($picture_url_style) {
            case 'id-file':
                $url .= is_scalar($image_id) ? (string) $image_id : '';
                if (isset($params['image_file']) and is_string($params['image_file'])) {
                    $url .= '-' . StringHelper::str2url(StringHelper::getFilenameWoExtension($params['image_file']));
                }

                break;
            case 'file':
                if (isset($params['image_file']) and is_string($params['image_file'])) {
                    $fname_wo_ext = StringHelper::getFilenameWoExtension($params['image_file']);
                    if (ord($fname_wo_ext[0]) > ord('9') or ! (bool) preg_match('/^\d+(-|$)/', $fname_wo_ext)) {
                        $url .= $fname_wo_ext;

                        break;
                    }
                }
                // no break
            default:
                $url .= is_scalar($image_id) ? (string) $image_id : '';
        }
        if (! isset($params['category'])) { // make urls shorter ...
            unset($params['flat']);
        }
        $url .= $this->makeSectionInUrl($params);
        $url = $this->addWellKnownParamsInUrl($url, $params);

        return $url;
    }

    /**
     * Adds to the url the chronology and start parameters.
     *
     * @param array<string, mixed> $params
     */
    public function addWellKnownParamsInUrl(string $url, array $params): string
    {
        if (isset($params['chronology_field'])) {
            $chronology_field = $params['chronology_field'];
            $url .= '/' . (is_scalar($chronology_field) ? (string) $chronology_field : '');

            $chronology_style = $params['chronology_style'] ?? null;
            $url .= '-' . (is_scalar($chronology_style) ? (string) $chronology_style : '');

            if (isset($params['chronology_view'])) {
                $chronology_view = $params['chronology_view'];
                $url .= '-' . (is_scalar($chronology_view) ? (string) $chronology_view : '');
            }
            if (isset($params['chronology_date']) && $params['chronology_date'] !== []) {
                $chronology_date = $params['chronology_date'];
                $url .= '-' . implode('-', is_array($chronology_date) ? array_filter($chronology_date, is_scalar(...)) : []);
            }
        }

        if (isset($params['flat'])) {
            $url .= '/flat';
        }

        if (isset($params['start']) and $params['start'] > 0) {
            $start = $params['start'];
            $url .= '/start-' . (is_scalar($start) ? (string) $start : '');
        }

        return $url;
    }

    /**
     * Return the section token of an index or picture URL.
     *
     * Depending on section, other parameters are required (see method body
     * for details).
     *
     * @param array<string, mixed> $params
     */
    public function makeSectionInUrl(array $params): string
    {
        $section_string = '';
        $section_raw = $params['section'] ?? null;
        $section = is_string($section_raw) ? $section_raw : null;
        if (! isset($section)) {
            $section_of = [
                'category' => 'categories',
                'tags' => 'tags',
                'list' => 'list',
                'search' => 'search',
            ];

            foreach ($section_of as $param => $s) {
                if (isset($params[$param])) {
                    $section = $s;
                }
            }

            if (! isset($section)) {
                $section = 'none';
            }
        }

        switch ($section) {
            case 'categories':

                if (! isset($params['category'])) {
                    $section_string .= '/categories';
                } else {
                    $category_info = $params['category'];
                    if (! is_array($category_info)) {
                        $category_info = [];
                    }

                    isset($category_info['name']) or trigger_error(
                        'makeSectionInUrl category name not set',
                        E_USER_WARNING,
                    );

                    array_key_exists('permalink', $category_info) or trigger_error(
                        'makeSectionInUrl category permalink not set',
                        E_USER_WARNING,
                    );

                    $section_string .= '/category/';
                    if (! isset($category_info['permalink']) || $category_info['permalink'] === '') {
                        $category_id = $category_info['id'] ?? null;
                        $section_string .= is_scalar($category_id) ? (string) $category_id : '';
                        if ($this->currentConfig->categoryUrlStyle === 'id-name') {
                            $category_name = $category_info['name'] ?? null;
                            $section_string .= '-' . StringHelper::str2url(is_string($category_name) ? $category_name : '');
                        }
                    } else {
                        $category_permalink = $category_info['permalink'];
                        $section_string .= is_scalar($category_permalink) ? (string) $category_permalink : '';
                    }

                    if (isset($params['combined_categories']) and is_array($params['combined_categories'])) {
                        foreach ($params['combined_categories'] as $category) {
                            $section_string .= '/';

                            if (! is_array($category)) {
                                $category = [];
                            }

                            if (! isset($category['permalink']) || $category['permalink'] === '') {
                                $combined_id = $category['id'] ?? null;
                                $section_string .= is_scalar($combined_id) ? (string) $combined_id : '';
                                if ($this->currentConfig->categoryUrlStyle === 'id-name') {
                                    $combined_name = $category['name'] ?? null;
                                    $section_string .= '-' . StringHelper::str2url(is_string($combined_name) ? $combined_name : '');
                                }
                            } else {
                                $combined_permalink = $category['permalink'];
                                $section_string .= is_scalar($combined_permalink) ? (string) $combined_permalink : '';
                            }
                        }
                    }
                }

                break;

            case 'tags':

                $section_string .= '/tags';

                $tags_param = $params['tags'] ?? [];
                $tag_url_style = $this->currentConfig->tagUrlStyle;
                foreach ((is_array($tags_param) ? $tags_param : []) as $tag) {
                    if (! is_array($tag)) {
                        $tag = [];
                    }
                    $tag_id = $tag['id'] ?? null;
                    $tag_url_name = $tag['url_name'] ?? null;
                    switch ($tag_url_style) {
                        case 'id':
                            $section_string .= '/' . (is_scalar($tag_id) ? (string) $tag_id : '');

                            break;
                        case 'tag':
                            if (isset($tag_url_name) && is_scalar($tag_url_name)) {
                                $section_string .= '/' . (string) $tag_url_name;

                                break;
                            }
                            // no break
                        default:
                            $section_string .= '/' . (is_scalar($tag_id) ? (string) $tag_id : '');
                            if (isset($tag_url_name) && is_scalar($tag_url_name)) {
                                $section_string .= '-' . (string) $tag_url_name;
                            }
                    }
                }

                break;

            case 'search':

                $search_param = $params['search'] ?? null;
                $section_string .= '/search/' . (is_scalar($search_param) ? (string) $search_param : '');

                break;

            case 'list':

                $list_param = $params['list'] ?? [];
                $section_string .= '/list/' . implode(',', is_array($list_param) ? array_filter($list_param, is_scalar(...)) : []);

                break;

            case 'none':

                break;

            default:

                $section_string .= '/' . $section;

        }

        return $section_string;
    }

    /**
     * The reverse of makeSectionInUrl().
     * Returns the 'section' (categories/tags/...) and the data associated
     * with it.
     *
     * Depending on section, other parameters are returned (category/tags/
     * list/...). 'category' is a real CategoryInfo object, 'combined_categories'
     * a list<CategoryInfo> -- CategoryService::getCategoryInfo()'s own real
     * result, not unboxed to array here; SectionContext::toUrlParams() is
     * the one real place that later needs the array shape (UrlService's own
     * make*Url() $params stay a generic array by design), and converts via
     * CategoryInfo::toArray() right at that boundary.
     *
     * @param string[] $tokens of url tokens to parse
     * @param int $nextToken the index in the array of url tokens; in/out
     * @param RedirectServiceInterface $redirectService used to issue the
     *   badRequest()/pageNotFound() redirects triggered when a token fails
     *   to resolve (unknown permalink, missing tag, ...)
     * @return array<string, mixed>
     */
    #[Override]
    public function parseSectionUrl(array $tokens, int &$nextToken, RedirectServiceInterface $redirectService): array
    {
        $page = [];
        if (isset($tokens[$nextToken]) and str_starts_with($tokens[$nextToken], 'categor')) {
            $page['section'] = Section::Categories;
            $nextToken++;

            $loop_counter = 0;

            /** @var int|numeric-string|null $category */
            $category = null;
            /** @var array<int, int|numeric-string> $combined_category_ids */
            $combined_category_ids = [];
            /** @var array{cat_url_name?: string, cat_permalink?: string} $hit_by */
            $hit_by = [];

            $categoryService = $this->categoryService();

            while (isset($tokens[$nextToken])) {
                if ($loop_counter++ > count($tokens) + 10) {
                    $this->htmlRenderer->fatalError('infinite loop?');
                }

                if (
                    str_starts_with($tokens[$nextToken], 'created-')
                    or str_starts_with($tokens[$nextToken], 'posted-')
                    or str_starts_with($tokens[$nextToken], 'start-')
                    or str_starts_with($tokens[$nextToken], 'startcat-')
                    or $tokens[$nextToken] === 'flat'
                ) {
                    break;
                }

                if ((bool) preg_match('/^(\d+)(?:-(.+))?$/', $tokens[$nextToken], $matches)) {
                    if (isset($matches[2])) {
                        $hit_by['cat_url_name'] = $matches[2];
                    }

                    if ($category === null) {
                        $category = $matches[1];
                    } else {
                        $combined_category_ids[] = $matches[1];
                    }
                    $nextToken++;
                } else { // try a permalink
                    $maybe_permalinks = [];
                    $current_token = $nextToken;
                    while (isset($tokens[$current_token])
                        and ! str_starts_with($tokens[$current_token], 'created-')
                        and ! str_starts_with($tokens[$current_token], 'posted-')
                        and ! str_starts_with($tokens[$current_token], 'start-')
                        and ! str_starts_with($tokens[$current_token], 'startcat-')
                        and $tokens[$current_token] !== 'flat') {
                        if ($maybe_permalinks === []) {
                            $maybe_permalinks[] = $tokens[$current_token];
                        } else {
                            $maybe_permalinks[] =
                                $maybe_permalinks[count($maybe_permalinks) - 1]
                                . '/' . $tokens[$current_token];
                        }
                        $current_token++;
                    }

                    if ((bool) count($maybe_permalinks)) {
                        $cat_id = $categoryService->findCategoryIdFromPermalinks($maybe_permalinks, $perma_index, new PermalinkRepository($this->entityManager));
                        // get_cat_id_from_permalinks() always sets $perma_index
                        // whenever it returns non-null (see its own docblock) --
                        // PHPStan can't correlate a by-ref out-param with the
                        // return value, so this is a real invariant, not a
                        // reachable branch.
                        if (isset($cat_id) && is_int($perma_index)) {
                            $nextToken += $perma_index + 1;

                            if ($category === null) {
                                $category = $cat_id;
                                $hit_by['cat_permalink'] = $maybe_permalinks[$perma_index];
                            } else {
                                $combined_category_ids[] = $cat_id;
                            }
                        } else {
                            $this->htmlRenderer->pageNotFound($redirectService, $this->lang->t('Permalink for album not found'));
                        }
                    }
                }
            }

            if ($hit_by !== []) {
                $page['hit_by'] = $hit_by;
            }

            if ($category !== null) {
                $result = $categoryService->getCategoryInfo((int) $category);
                if (! $result instanceof CategoryInfo) {
                    $this->htmlRenderer->pageNotFound($redirectService, $this->lang->t('Requested album does not exist'));
                }
                $page['category'] = $result;
            }

            if ($combined_category_ids !== []) {
                $combined_categories = [];

                foreach ($combined_category_ids as $cat_id) {
                    $result = $categoryService->getCategoryInfo((int) $cat_id);
                    if (! $result instanceof CategoryInfo) {
                        $this->htmlRenderer->pageNotFound($redirectService, $this->lang->t('Requested album does not exist'));
                    }

                    $combined_categories[] = $result;
                }

                $page['combined_categories'] = $combined_categories;
            }
        } elseif (($tokens[$nextToken] ?? null) === 'tags') {
            $page['section'] = Section::Tags;
            $page['tags'] = [];

            $nextToken++;
            $i = $nextToken;

            $requested_tag_ids = [];
            $requested_tag_url_names = [];

            while (isset($tokens[$i])) {
                if (str_starts_with($tokens[$i], 'created-')
                     or str_starts_with($tokens[$i], 'posted-')
                     or str_starts_with($tokens[$i], 'start-')) {
                    break;
                }

                if ($this->currentConfig->tagUrlStyle !== 'tag' and (bool) preg_match('/^(\d+)(?:-(.*)|)$/', $tokens[$i], $matches)) {
                    $requested_tag_ids[] = $matches[1];
                } else {
                    $requested_tag_url_names[] = $tokens[$i];
                }
                $i++;
            }
            $nextToken = $i;

            if ($requested_tag_ids === [] && $requested_tag_url_names === []) {
                $this->htmlRenderer->badRequest($redirectService, 'at least one tag required');
            }

            $page['tags'] = new TagService($this->lang, $this->entityManager->getRepository(TagEntity::class), $this->permissionService(), new ActivityService($this->entityManager->getRepository(ActivityEntity::class)), $this->eventDispatcher, $this->currentUser, $this->currentConfig, $this->currentLogger(), new ImageService($this->entityManager->getRepository(ImageEntity::class), new ActivityService($this->entityManager->getRepository(ActivityEntity::class)), $this->sessionService(), $this->eventDispatcher, $this->currentConfig, $this->paths(), $this->categoryService()))
                ->findTags($requested_tag_ids, $requested_tag_url_names);
            if ($page['tags'] === []) {
                $this->htmlRenderer->pageNotFound($redirectService, $this->lang->t('Requested tag does not exist'), $this->getRootUrl() . 'tags.php');
            }
        } elseif (($tokens[$nextToken] ?? null) === 'favorites') {
            $page['section'] = Section::Favorites;
            $nextToken++;
        } elseif (($tokens[$nextToken] ?? null) === 'most_visited') {
            $page['section'] = Section::MostVisited;
            $nextToken++;
        } elseif (($tokens[$nextToken] ?? null) === 'best_rated') {
            $page['section'] = Section::BestRated;
            $nextToken++;
        } elseif (($tokens[$nextToken] ?? null) === 'recent_pics') {
            $page['section'] = Section::RecentPics;
            $nextToken++;
        } elseif (($tokens[$nextToken] ?? null) === 'recent_cats') {
            $page['section'] = Section::RecentCats;
            $nextToken++;
        } elseif (($tokens[$nextToken] ?? null) === 'search') {
            $page['section'] = Section::Search;
            $nextToken++;

            preg_match('/^(psk-\d{8}-[a-zA-Z0-9]{10})$/', ($tokens[$nextToken] ?? ''), $matches);
            if (! isset($matches[1])) {
                preg_match('/(\d+)/', ($tokens[$nextToken] ?? ''), $matches);
                if (! isset($matches[1])) {
                    $this->htmlRenderer->badRequest($redirectService, 'search identifier is missing');
                }
            }
            $page['search'] = $matches[1];
            $nextToken++;
        } elseif (($tokens[$nextToken] ?? null) === 'list') {
            $page['section'] = Section::ListView;
            $nextToken++;

            $page['list'] = [];

            // No pictures
            if (! isset($tokens[$nextToken]) || $tokens[$nextToken] === '') {
                // Add dummy element list
                $page['list'][] = NoMatchSentinel::ID;
            }
            // With pictures list
            else {
                if (! (bool) preg_match('/^\d+(,\d+)*$/', $tokens[$nextToken])) {
                    $this->htmlRenderer->badRequest($redirectService, 'wrong format on list GET parameter');
                }
                foreach (explode(',', $tokens[$nextToken]) as $image_id) {
                    $page['list'][] = $image_id;
                }
            }
            $nextToken++;
        }

        return $page;
    }

    /**
     * The reverse of addWellKnownParamsInUrl().
     * Parses start, flat and chronology from url tokens.
     *
     * @param string[] $tokens
     * @return array{flat?: true, chronology_field?: string, chronology_style?: string, chronology_view?: string, chronology_date?: list<string>, start?: string, startcat?: string}
     */
    #[Override]
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array
    {
        $page = [];
        while (isset($tokens[$i])) {
            if ($tokens[$i] === 'flat') {
                // indicate a special list of images
                $page['flat'] = true;
            } elseif (str_starts_with($tokens[$i], 'created-') or str_starts_with($tokens[$i], 'posted-')) {
                $chronology_tokens = explode('-', $tokens[$i]);

                $page['chronology_field'] = $chronology_tokens[0];

                array_shift($chronology_tokens);
                $page['chronology_style'] = $chronology_tokens[0];

                if (! in_array($page['chronology_style'], ['monthly', 'weekly'], true)) {
                    $this->htmlRenderer->fatalError('bad chronology field (style)');
                }

                array_shift($chronology_tokens);
                if (count($chronology_tokens) > 0) {
                    if ($chronology_tokens[0] === 'list' or
                        $chronology_tokens[0] === 'calendar') {
                        $page['chronology_view'] = $chronology_tokens[0];
                        array_shift($chronology_tokens);
                    }
                    $page['chronology_date'] = $chronology_tokens;

                    foreach ($page['chronology_date'] as $date_token) {
                        // each date part must be an integer (number of the year, number of the month, number of the week or number of the day)
                        if (! (bool) preg_match('/^(\d+|any)$/', $date_token)) {
                            $this->htmlRenderer->fatalError('bad chronology field (date)');
                        }
                    }
                }
            } elseif ((bool) preg_match('/^start-(\d+)/', $tokens[$i], $matches)) {
                $page['start'] = $matches[1];
            } elseif ((bool) preg_match('/^startcat-(\d+)/', $tokens[$i], $matches)) {
                $page['startcat'] = $matches[1];
            }
            $i++;
        }

        return $page;
    }

    /**
     * @param int|string $id image id
     * @param string $whatPart one of 'e' (element), 'r' (representative)
     */
    #[Override]
    public function getActionUrl(int|string $id, string $whatPart, bool $download): string
    {
        $params = [
            'id' => $id,
            'part' => $whatPart,
        ];
        if ($download) {
            $params['download'] = null;
        }

        return $this->addUrlParams($this->getRootUrl() . 'action.php', $params);
    }

    /**
     * @param array<string, mixed> $elementInfo containing element information
     * from db; only 'path' is actually read
     */
    #[Override]
    public function getElementUrl(array $elementInfo): string
    {
        $path = $elementInfo['path'] ?? null;
        if (! is_string($path)) {
            // Never reached with a real image row (ImageEntity::$path is a
            // non-nullable string column) -- mirrors this file's own
            // is_scalar()-cast-or-empty fallback used elsewhere for a
            // declared-mixed value that's always scalar in practice.
            return is_scalar($path) ? (string) $path : '';
        }

        $url = $path;
        if (! $this->urlIsRemote($url)) {
            $url = $this->embellishUrl($this->getRootUrl() . $url);
        }

        return $url;
    }

    /**
     * Indicate to build url with full path.
     */
    #[Override]
    public function setMakeFullUrl(): void
    {
        $this->rootPathOverride->push($this->getAbsoluteRootUrl());
    }

    /**
     * Restore old parameter to build url with full path.
     */
    #[Override]
    public function unsetMakeFullUrl(): void
    {
        $this->rootPathOverride->pop();
    }

    /**
     * Embellish the url argument.
     */
    #[Override]
    public function embellishUrl(string $url): string
    {
        $url = str_replace('/./', '/', $url);
        while (($dotdot = strpos($url, '/../', 1)) !== false) {
            $before = strrpos($url, '/', -(strlen($url) - $dotdot + 1));
            if ($before !== false) {
                $url = substr_replace($url, '', $before, $dotdot - $before + 3);
            } else {
                break;
            }
        }

        return $url;
    }

    /**
     * Returns the 'home page' of this gallery.
     */
    #[Override]
    public function getGalleryHomeUrl(): string
    {
        $gallery_url = $this->currentConfig->galleryUrl;
        if (is_string($gallery_url) && $gallery_url !== '') {
            if ($this->urlIsRemote($gallery_url) or $gallery_url[0] === '/') {
                return $gallery_url;
            }

            return $this->getRootUrl() . $gallery_url;
        }

        return $this->makeIndexUrl();
    }

    /**
     * Returns $_SERVER['QUERY_STRING'] whithout keys given in parameters.
     *
     * @param string[] $rejects
     * @param bool $escape escape *&* to *&amp;*
     */
    #[Override]
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
    {
        $query_string = $_SERVER['QUERY_STRING'] ?? null;
        if (! is_string($query_string) || $query_string === '') {
            return '';
        }

        parse_str($query_string, $vars);

        $vars = array_diff_key($vars, array_flip($rejects));

        return '?' . http_build_query($vars, '', $escape ? '&amp;' : '&');
    }

    /**
     * Returns true if the url is absolute (begins with http).
     */
    #[Override]
    public function urlIsRemote(string $url): bool
    {
        return str_starts_with($url, 'http://')
            or str_starts_with($url, 'https://');
    }

    /**
     * List favorite image_ids of the current user. Every real caller only
     * checks key existence (isset($favorites[$id])) -- the value is always
     * the query's own literal `1`, never read for its own sake.
     *
     * @return array<int, int>
     */
    #[Override]
    public function getUserFavorites(): array
    {
        if ($this->accessLevelChecker()->isAGuest()) {
            return [];
        }

        $currentUserId = $this->currentUser->get()
            ->id;

        $imageIds = new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig)
            ->findFavoriteImageIds($currentUserId);

        $favorites = [];
        foreach ($imageIds as $image_id) {
            $favorites[$image_id] = 1;
        }

        return $favorites;
    }
}
