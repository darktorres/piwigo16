<?php

declare(strict_types=1);

namespace Piwigo\Url;

use Piwigo\Auth\CookieService;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\StringUtil;
use Piwigo\Event\Picture\GetElementUrl;
use Piwigo\Html\HtmlService;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserFavoriteRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

final class UrlService
{
    /** Override pushed by setMakeFullUrl(); null when not active. */
    private static ?string $rootPathOverride = null;
    /** Reference count for nested setMakeFullUrl()/unsetMakeFullUrl() pairs. */
    private static int $rootPathRefCount = 0;

    public function __construct(
        private readonly CategoryService $categoryService,
        private readonly HtmlService $htmlService,
        private readonly TagService $tagService,
        private readonly PermissionService $permissionService,
        private readonly UserFavoriteRepository $userFavoriteRepository,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public static function getRootUrl(): string
    {
        if (self::$rootPathOverride !== null) {
            return self::$rootPathOverride;
        }
        return SectionContextRegistry::current()->rootPath;
    }

    public static function getAbsoluteRootUrl(bool $withScheme = true): string
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) and 'https' == $_SERVER['HTTP_X_FORWARDED_PROTO']) {
            $_SERVER['HTTPS'] = 'on';
        }

        $url = '';
        if ($withScheme) {
            $isHttps = false;
            /** @psalm-var mixed $httpsRaw */
            $httpsRaw = $_SERVER['HTTPS'] ?? null;
            if ($httpsRaw !== null &&
              ((is_string($httpsRaw) && strtolower($httpsRaw) === 'on') || $httpsRaw == 1)) {
                $isHttps = true;
                $url .= 'https://';
            } else {
                $url .= 'http://';
            }
            /** @psalm-var mixed $forwardedHost */
            $forwardedHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null;
            if ($forwardedHost !== null) {
                $url .= is_string($forwardedHost) ? $forwardedHost : '';
            } else {
                /** @psalm-var mixed $hostRaw */
                $hostRaw = $_SERVER['HTTP_HOST'] ?? '';
                $url    .= is_string($hostRaw) ? $hostRaw : '';

                $urlPort = null;

                if ('none' == Config::urlPort()) {
                    // do nothing
                } elseif ('auto' == Config::urlPort()) {
                    /** @psalm-var mixed $portRaw */
                    $portRaw    = $_SERVER['SERVER_PORT'] ?? 80;
                    $serverPort = is_numeric($portRaw) ? (int) $portRaw : 80;
                    if ((!$isHttps && $serverPort !== 80) || ($isHttps && $serverPort !== 443)) {
                        $urlPort = ':' . $serverPort;
                    }
                } else {
                    $urlPort = ':' . Config::urlPort();
                }

                if ($urlPort !== null && strrchr($url, ':') != $urlPort) {
                    $url .= $urlPort;
                }
            }
        }
        $url .= CookieService::cookiePath();
        return $url;
    }

    /** @param array<mixed> $params */
    public function addUrlParams(string $url, array $params, string $argSeparator = '&'): string
    {
        if (!empty($params)) {

            $isFirst = true;
            foreach ($params as $param => $val) {
                if ($isFirst) {
                    $isFirst = false;
                    $url .= (!str_contains($url, '?')) ? '?' : $argSeparator;
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

    /** @param array<mixed> $params */
    public function makeIndexUrl(array $params = []): string
    {
        $url = self::getRootUrl() . 'index.php?';

        $urlBeforeParams = $url;
        $url .= $this->makeSectionInUrl($params);
        $url  = $this->addWellKnownParamsInUrl($url, $params);

        if ($url == $urlBeforeParams) {
            $url = self::getAbsoluteRootUrl(self::urlIsRemote($url));
        }

        return $url;
    }

    /**
     * @param array<mixed> $redefined
     * @param string[]     $removed
     */
    public function duplicateIndexUrl(array $redefined = [], array $removed = []): string
    {
        return $this->makeIndexUrl($this->paramsForDuplication($redefined, $removed));
    }

    /**
     * @param array<mixed> $redefined
     * @param string[]     $removed
     * @return array<mixed>
     */
    public function paramsForDuplication(array $redefined, array $removed): array
    {
        $params = SectionContextRegistry::current()->toUrlParams();

        foreach ($removed as $paramKey) {
            unset($params[$paramKey]);
        }

        foreach ($redefined as $redefinedParam => $redefinedValue) {
            $params[$redefinedParam] = $redefinedValue;
        }

        return $params;
    }

    /**
     * @param array<mixed> $redefined
     * @param string[]     $removed
     */
    public function duplicatePictureUrl(array $redefined = [], array $removed = []): string
    {
        return $this->makePictureUrl($this->paramsForDuplication($redefined, $removed));
    }

    /** @param array<mixed> $params */
    public function makePictureUrl(array $params): string
    {
        $url = self::getRootUrl() . 'index.php?/picture/';
        switch (Config::pictureUrlStyle()) {
            case 'id-file':
                $url .= is_scalar($params['image_id'] ?? null) ? (string) $params['image_id'] : '';
                if (isset($params['image_file'])) {
                    $url .= '-' . StringUtil::str2url(StringUtil::getFilenameWoExtension(is_string($params['image_file']) ? $params['image_file'] : ''));
                }
                break;
            case 'file':
                if (isset($params['image_file'])) {
                    $fnameWoExt = StringUtil::getFilenameWoExtension(is_string($params['image_file']) ? $params['image_file'] : '');
                    if (ord($fnameWoExt[0]) > ord('9') or !preg_match('/^\d+(-|$)/', $fnameWoExt)) {
                        $url .= $fnameWoExt;
                        break;
                    }
                }
                // no break
            default:
                $url .= is_scalar($params['image_id'] ?? null) ? (string) $params['image_id'] : '';
        }
        if (!isset($params['category'])) {
            unset($params['flat']);
        }
        $url .= $this->makeSectionInUrl($params);
        $url  = $this->addWellKnownParamsInUrl($url, $params);
        return $url;
    }

    /** @param array<mixed> $params */
    public function addWellKnownParamsInUrl(string $url, array $params): string
    {
        if (isset($params['chronology_field'])) {
            $chronoFieldRaw = $params['chronology_field'];
            $chronoStyleRaw = $params['chronology_style'] ?? null;
            $url .= '/' . (is_string($chronoFieldRaw) ? $chronoFieldRaw : '');
            $url .= '-' . (is_string($chronoStyleRaw) ? $chronoStyleRaw : '');
            if (isset($params['chronology_view'])) {
                $url .= '-' . (is_string($params['chronology_view']) ? $params['chronology_view'] : '');
            }
            if (!empty($params['chronology_date'])) {
                $url .= '-' . implode('-', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', is_array($params['chronology_date']) ? $params['chronology_date'] : []));
            }
        }

        if (isset($params['flat'])) {
            $url .= '/flat';
        }

        if (isset($params['start']) and $params['start'] > 0) {
            $url .= '/start-' . (is_string($params['start']) ? $params['start'] : '');
        }
        return $url;
    }

    /** @param array<mixed> $params */
    public function makeSectionInUrl(array $params): string
    {
        $sectionString = '';
        $section       = $params['section'] ?? null;
        if (!isset($section)) {
            $sectionOf = [
                'category' => 'categories',
                'tags'     => 'tags',
                'list'     => 'list',
                'search'   => 'search',
            ];

            foreach ($sectionOf as $param => $s) {
                if (isset($params[$param])) {
                    $section = $s;
                }
            }

            if (!isset($section)) {
                $section = 'none';
            }
        }

        return $sectionString . match ($section) {
            'categories' => $this->buildCategoriesSection($params['category'] ?? null, $params['combined_categories'] ?? null),
            'tags'       => $this->buildTagsSection($params['tags'] ?? null),
            'search'     => '/search/' . (is_string($params['search'] ?? null) ? $params['search'] : ''),
            'list'       => '/list/' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', is_array($params['list'] ?? null) ? $params['list'] : [])),
            'none'       => '',
            default      => '/' . (is_scalar($section) ? (string) $section : ''),
        };
    }

    /**
     * Render the `/category/…(/…)*` segment for makeSectionInUrl()
     * given the primary category and any combined-category siblings.
     * Returns `/categories` when no category is provided (the
     * gallery-wide "all categories" landing page).
     */
    private function buildCategoriesSection(mixed $primary, mixed $combined): string
    {
        if ($primary === null) {
            return '/categories';
        }
        $cat = is_array($primary) ? $primary : [];
        if (!isset($cat['name'])) {
            throw new \InvalidArgumentException('make_section_in_url category name not set');
        }
        if (!array_key_exists('permalink', $cat)) {
            throw new \InvalidArgumentException('make_section_in_url category permalink not set');
        }

        $out = '/category/' . $this->renderCategoryIdent($cat);
        if ($combined === null) {
            return $out;
        }
        foreach ((array) $combined as $category) {
            if (!is_array($category)) {
                continue;
            }
            $out .= '/' . $this->renderCategoryIdent($category);
        }
        return $out;
    }

    /**
     * Render a single category as the URL-style id-or-permalink
     * fragment (without leading slash). Used by both the primary
     * `/category/…` segment and the combined-categories suffix.
     *
     * @param array<mixed> $cat
     */
    private function renderCategoryIdent(array $cat): string
    {
        if (!empty($cat['permalink'])) {
            return is_string($cat['permalink']) ? $cat['permalink'] : '';
        }
        $catIdRaw = $cat['id'] ?? null;
        $out      = is_scalar($catIdRaw) ? (string) $catIdRaw : '';
        if (Config::categoryUrlStyle() == 'id-name') {
            $catNameRaw = $cat['name'] ?? null;
            $out       .= '-' . StringUtil::str2url(is_string($catNameRaw) ? $catNameRaw : '');
        }
        return $out;
    }

    /**
     * Render the `/tags/…` segment for makeSectionInUrl(). Each tag
     * is rendered per Config::tagUrlStyle(): `'id'` → numeric id,
     * `'tag'` → url_name (falls back to id when missing), default →
     * id-then-url_name combined.
     */
    private function buildTagsSection(mixed $tags): string
    {
        $out = '/tags';
        foreach ((array) $tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $idStr  = is_scalar($tag['id'] ?? null) ? (string) $tag['id'] : '';
            $urlRaw = $tag['url_name'] ?? null;
            $urlStr = is_string($urlRaw) ? $urlRaw : '';

            $out .= match (Config::tagUrlStyle()) {
                'id'    => '/' . $idStr,
                'tag'   => isset($tag['url_name']) ? '/' . $urlStr : '/' . $idStr,
                default => '/' . $idStr . (isset($tag['url_name']) ? '-' . $urlStr : ''),
            };
        }
        return $out;
    }

    /**
     * @param string[] $tokens
     * @return array<mixed>
     */
    public function parseSectionUrl(array $tokens, int &$nextToken): array
    {
        $base  = ['hit_by' => [], 'combined_categories' => null];
        $token = $tokens[$nextToken] ?? null;

        if ($token !== null && str_starts_with($token, 'categor')) {
            return $this->parseCategoriesSection($tokens, $nextToken);
        }
        if ('tags' === $token) {
            return array_merge($base, $this->parseTagsSection($tokens, $nextToken));
        }
        if ('search' === $token) {
            return array_merge($base, $this->parseSearchSection($tokens, $nextToken));
        }
        if ('list' === $token) {
            return array_merge($base, $this->parseListSection($tokens, $nextToken));
        }

        $simpleSection = match ($token) {
            'favorites', 'most_visited', 'best_rated',
            'recent_pics', 'recent_cats'               => $token,
            default                                    => null,
        };
        if ($simpleSection !== null) {
            $base['section'] = $simpleSection;
            $nextToken++;
        }
        return $base;
    }

    /**
     * @param string[] $tokens
     * @return array<string, mixed>
     */
    private function parseCategoriesSection(array $tokens, int &$nextToken): array
    {
        $nextToken++;
        $loopCounter = 0;
        /** @var array<string, string> $hitBy */
        $hitBy    = [];
        $category = null;   // int|string|null — raw parsed ID before getCatInfo resolve
        $combined = [];     // list<int|string>

        while (isset($tokens[$nextToken])) {
            if ($loopCounter++ > count($tokens) + 10) {
                throw new \LogicException('infinite loop?');
            }

            if (
                str_starts_with($tokens[$nextToken], 'created-')
                or str_starts_with($tokens[$nextToken], 'posted-')
                or str_starts_with($tokens[$nextToken], 'start-')
                or str_starts_with($tokens[$nextToken], 'startcat-')
                or 'flat' == $tokens[$nextToken]
            ) {
                break;
            }

            if (preg_match('/^(\d+)(?:-(.+))?$/', $tokens[$nextToken], $matches)) {
                if (isset($matches[2])) {
                    $hitBy['cat_url_name'] = $matches[2];
                }
                if ($category === null) {
                    $category = $matches[1];
                } else {
                    $combined[] = $matches[1];
                }
                $nextToken++;
            } else {
                $this->resolvePermalinkTokens($tokens, $nextToken, $hitBy, $category, $combined);
            }
        }

        $resolvedCategory = null;
        if ($category !== null) {
            $result = $this->categoryService->getCatInfo($category);
            if ($result === null || count($result) === 0) {
                $this->htmlService->pageNotFound(Lang::t('Requested album does not exist'));
            }
            $resolvedCategory = $result;
        }

        $resolvedCombined = null;
        if ($combined !== []) {
            $resolvedCombined = [];
            foreach ($combined as $catId) {
                $result = $this->categoryService->getCatInfo($catId);
                if ($result === null || count($result) === 0) {
                    $this->htmlService->pageNotFound(Lang::t('Requested album does not exist'));
                }
                $resolvedCombined[] = $result;
            }
        }

        $page = ['section' => 'categories', 'hit_by' => $hitBy, 'combined_categories' => $resolvedCombined];
        if ($resolvedCategory !== null) {
            $page['category'] = $resolvedCategory;
        }
        return $page;
    }

    /**
     * Resolves the current token position as a permalink (or chain of slugs).
     * Advances $nextToken past the matched tokens on success.
     *
     * @param  string[]              $tokens
     * @param  array<string, string> $hitBy
     * @param  list<int|string>      $combined
     */
    private function resolvePermalinkTokens(
        array $tokens,
        int &$nextToken,
        array &$hitBy,
        int|string|null &$category,
        array &$combined,
    ): void {
        $maybePermalinks = [];
        $currentToken    = $nextToken;
        while (isset($tokens[$currentToken])
            and !str_starts_with($tokens[$currentToken], 'created-')
            and !str_starts_with($tokens[$currentToken], 'posted-')
            and $tokens[$currentToken] != 'flat') {
            $maybePermalinks[] = empty($maybePermalinks)
                ? $tokens[$currentToken]
                : $maybePermalinks[count($maybePermalinks) - 1] . '/' . $tokens[$currentToken];
            $currentToken++;
        }

        if ($maybePermalinks === []) {
            return;
        }

        $permaIndex = 0;
        $catId      = $this->categoryService->getCatIdFromPermalinks($maybePermalinks, $permaIndex);
        if ($catId === null) {
            $this->htmlService->pageNotFound(Lang::t('Permalink for album not found'));
            return;
        }

        $nextToken += $permaIndex + 1;
        if ($category === null) {
            $category              = $catId;
            $hitBy['cat_permalink'] = $maybePermalinks[$permaIndex];
        } else {
            $combined[] = $catId;
        }
    }

    /**
     * @param string[] $tokens
     * @return array<string, mixed>
     */
    private function parseTagsSection(array $tokens, int &$nextToken): array
    {
        $nextToken++;
        $requestedTagIds      = [];
        $requestedTagUrlNames = [];

        while (isset($tokens[$nextToken])) {
            if (str_starts_with($tokens[$nextToken], 'created-')
                or str_starts_with($tokens[$nextToken], 'posted-')
                or str_starts_with($tokens[$nextToken], 'start-')) {
                break;
            }
            if (Config::tagUrlStyle() != 'tag' and preg_match('/^(\d+)(?:-(.*)|)$/', $tokens[$nextToken], $matches)) {
                $requestedTagIds[] = $matches[1];
            } else {
                $requestedTagUrlNames[] = $tokens[$nextToken];
            }
            $nextToken++;
        }

        if (empty($requestedTagIds) && empty($requestedTagUrlNames)) {
            $this->htmlService->badRequest('at least one tag required');
        }

        $tags = $this->tagService->findTags($requestedTagIds, $requestedTagUrlNames);
        if (empty($tags)) {
            $this->htmlService->pageNotFound(Lang::t('Requested tag does not exist'), Kernel::service(UrlGenerator::class)->tagsPage());
        }

        return ['section' => 'tags', 'tags' => $tags];
    }

    /**
     * @param string[] $tokens
     * @return array<string, mixed>
     */
    private function parseSearchSection(array $tokens, int &$nextToken): array
    {
        $nextToken++;

        preg_match('/^(psk-\d{8}-[a-zA-Z0-9]{10})$/', $tokens[$nextToken] ?? '', $matches);
        if (!isset($matches[1])) {
            preg_match('/(\d+)/', $tokens[$nextToken] ?? '', $matches);
            if (!isset($matches[1])) {
                $this->htmlService->badRequest('search identifier is missing');
                return ['section' => 'search'];
            }
        }
        $nextToken++;
        return ['section' => 'search', 'search' => $matches[1]];
    }

    /**
     * @param string[] $tokens
     * @return array<string, mixed>
     */
    private function parseListSection(array $tokens, int &$nextToken): array
    {
        $nextToken++;
        $list = [];

        if (!isset($tokens[$nextToken]) || $tokens[$nextToken] === '') {
            $list[] = -1;
        } else {
            if (!preg_match('/^\d+(,\d+)*$/', $tokens[$nextToken])) {
                $this->htmlService->badRequest('wrong format on list GET parameter');
            }
            foreach (explode(',', $tokens[$nextToken]) as $imageId) {
                $list[] = $imageId;
            }
        }
        $nextToken++;
        return ['section' => 'list', 'list' => $list];
    }

    /**
     * @param string[] $tokens
     * @return array<mixed>
     */
    public function parseWellKnownParamsUrl(array $tokens, int &$i): array
    {
        $page = [];
        while (isset($tokens[$i])) {
            if ('flat' == $tokens[$i]) {
                $page['flat'] = true;
            } elseif (str_starts_with($tokens[$i], 'created-') or str_starts_with($tokens[$i], 'posted-')) {
                $chronologyTokens = explode('-', $tokens[$i]);

                $page['chronology_field'] = $chronologyTokens[0];

                array_shift($chronologyTokens);
                $page['chronology_style'] = $chronologyTokens[0];

                if (!in_array($page['chronology_style'], ['monthly', 'weekly'])) {
                    HtmlService::fatalError('bad chronology field (style)');
                }

                array_shift($chronologyTokens);
                if (count($chronologyTokens) > 0) {
                    if ('list' == $chronologyTokens[0] or
                        'calendar' == $chronologyTokens[0]) {
                        $page['chronology_view'] = $chronologyTokens[0];
                        array_shift($chronologyTokens);
                    }
                    $page['chronology_date'] = $chronologyTokens;

                    foreach ($page['chronology_date'] as $dateToken) {
                        if (!preg_match('/^(\d+|any)$/', $dateToken)) {
                            HtmlService::fatalError('bad chronology field (date)');
                        }
                    }
                }
            } elseif (preg_match('/^start-(\d+)/', $tokens[$i], $matches)) {
                $page['start'] = $matches[1];
            } elseif (preg_match('/^startcat-(\d+)/', $tokens[$i], $matches)) {
                $page['startcat'] = $matches[1];
            }
            $i++;
        }
        return $page;
    }

    public function getActionUrl(int|string $id, string $whatPart, bool $download): string
    {
        $params = [
            'id'   => (int) $id,
            'part' => $whatPart,
        ];
        if ($download) {
            $params['download'] = null;
        }

        return $this->addUrlParams(self::getRootUrl() . 'index.php?/action', $params);
    }

    /** @param array<string,mixed> $elementInfo */
    public function getElementUrl(array $elementInfo): string
    {
        $url = is_string($elementInfo['path'] ?? null) ? $elementInfo['path'] : '';
        if (!self::urlIsRemote($url)) {
            $url = self::embellishUrl(self::getRootUrl() . $url);
            $url = is_string($url) ? $url : '';
        }
        $event = new GetElementUrl($url, $elementInfo);
        $this->dispatcher->dispatch($event);
        return $event->url;
    }

    public function setMakeFullUrl(): void
    {
        if (self::$rootPathRefCount === 0) {
            self::$rootPathOverride = self::getAbsoluteRootUrl();
        }
        self::$rootPathRefCount++;
    }

    public function unsetMakeFullUrl(): void
    {
        if (self::$rootPathRefCount === 0) {
            return;
        }
        self::$rootPathRefCount--;
        if (self::$rootPathRefCount === 0) {
            self::$rootPathOverride = null;
        }
    }

    /**
     * @param string|string[] $url
     * @return string|string[]
     */
    public static function embellishUrl(string|array $url): string|array
    {
        if (is_array($url)) {
            return array_map(fn (string $u): string => is_string($r = self::embellishUrl($u)) ? $r : $u, $url);
        }
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

    public function getGalleryHomeUrl(): string
    {
        $galleryUrl = Config::galleryUrl() ?? '';
        if ($galleryUrl !== '') {
            if (self::urlIsRemote($galleryUrl) or $galleryUrl[0] == '/') {
                return $galleryUrl;
            }
            return self::getRootUrl() . $galleryUrl;
        } else {
            return $this->makeIndexUrl();
        }
    }

    /**
     * @param string[] $rejects
     */
    public function getQueryStringDiff(array $rejects = [], bool $escape = true): string
    {
        /** @var mixed $rawQs */
        $rawQs       = $_SERVER['QUERY_STRING'] ?? '';
        $queryString = is_string($rawQs) ? $rawQs : '';
        if ($queryString === '') {
            return '';
        }

        parse_str($queryString, $vars);

        $vars = array_diff_key($vars, array_flip($rejects));

        return '?' . http_build_query($vars, '', '&');
    }

    public static function urlIsRemote(string $url): bool
    {
        if (str_starts_with($url, 'http://')
          or str_starts_with($url, 'https://')) {
            return true;
        }
        return false;
    }

    /** @return array<int,true> */
    public function getUserFavorites(): array
    {
        if ($this->permissionService->isAGuest()) {
            return [];
        }
        $result = [];
        foreach ($this->userFavoriteRepository->findImageIdsByUserId(CurrentUser::get()->id) as $imageId) {
            $result[$imageId] = true;
        }
        return $result;
    }
}
