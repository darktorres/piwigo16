<?php

declare(strict_types=1);

namespace Piwigo\Url;

use Piwigo\Routing\Router;

/**
 * Typed facade for building application URLs.
 *
 * Simple PSR-15 route URLs (identification, register, profile, feed, …) are
 * built via Router::generate() so the path is always derived from the named
 * route table. All URLs use the server-agnostic index.php?/path format.
 *
 * Gallery / picture / tags / search URLs delegate to UrlService, which owns
 * the complex sub-token format (category/12-name/start-24, etc.).
 *
 * Admin and web-service URLs are built via named routes (/admin, /ws)
 * with optional query params appended for section and method arguments.
 *
 * @see UrlService  lower-level URL building used by legacy callers
 */
final readonly class UrlGenerator
{
    public function __construct(
        private Router     $router,
        private UrlService $urls,
    ) {
    }

    // ── Gallery / browse (delegate sub-token building to UrlService) ──────────

    public function gallery(): string
    {
        return $this->urls->getGalleryHomeUrl();
    }

    /** @param array<string,mixed> $category */
    public function category(array $category, int $start = 0): string
    {
        $params = ['category' => $category];
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $this->urls->makeIndexUrl($params);
    }

    /**
     * @param array<string,mixed>      $picture   Row with 'id' and optionally 'file'.
     * @param array<string,mixed>|null $category  Row with 'id', 'name', 'permalink'.
     */
    public function picture(array $picture, ?array $category = null): string
    {
        $params = ['image_id' => $picture['id'] ?? 0];
        if (isset($picture['file'])) {
            $params['image_file'] = $picture['file'];
        }
        if ($category !== null) {
            $params['category'] = $category;
        }
        return $this->urls->makePictureUrl($params);
    }

    /** @param list<array<string,mixed>> $tags */
    public function tags(array $tags, int $start = 0): string
    {
        $params = ['tags' => $tags];
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $this->urls->makeIndexUrl($params);
    }

    public function search(string|int $searchId, int $start = 0): string
    {
        $params = ['search' => $searchId];
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $this->urls->makeIndexUrl($params);
    }

    public function favorites(int $start = 0): string
    {
        $params = ['section' => 'favorites'];
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $this->urls->makeIndexUrl($params);
    }

    public function recentPics(int $start = 0): string
    {
        $params = ['section' => 'recent_pics'];
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $this->urls->makeIndexUrl($params);
    }

    public function bestRated(int $start = 0): string
    {
        $params = ['section' => 'best_rated'];
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $this->urls->makeIndexUrl($params);
    }

    public function mostVisited(int $start = 0): string
    {
        $params = ['section' => 'most_visited'];
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $this->urls->makeIndexUrl($params);
    }

    public function recentAlbums(int $start = 0): string
    {
        $params = ['section' => 'recent_cats'];
        if ($start > 0) {
            $params['start'] = $start;
        }
        return $this->urls->makeIndexUrl($params);
    }

    // ── Named PSR-15 routes (Router::generate() + URL-mode prefix) ────────────

    public function random(): string
    {
        return $this->routeUrl('random');
    }
    public function identification(): string
    {
        return $this->routeUrl('identification');
    }
    public function register(): string
    {
        return $this->routeUrl('register');
    }
    public function password(): string
    {
        return $this->routeUrl('password');
    }
    public function profile(): string
    {
        return $this->routeUrl('profile');
    }
    public function comments(): string
    {
        return $this->routeUrl('comments');
    }
    public function notification(): string
    {
        return $this->routeUrl('notification');
    }
    public function feed(): string
    {
        return $this->routeUrl('feed');
    }
    /** Tags cloud / alphabetic listing page (bare /tags, no specific tags selected). */
    public function tagsPage(): string
    {
        return $this->routeUrl('tags');
    }
    /** Search form submission URL (bare /search, no saved-search ID yet). */
    public function searchPage(): string
    {
        return $this->routeUrl('search');
    }
    public function about(): string
    {
        return $this->routeUrl('about');
    }
    public function nbm(): string
    {
        return $this->routeUrl('nbm');
    }
    public function popuphelp(string $page = ''): string
    {
        $base = $this->routeUrl('popuphelp');
        if ($page === '') {
            return $base;
        }
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . 'page=' . urlencode($page);
    }

    public function image(string $path): string
    {
        return $this->routeUrl('image', ['rest' => ltrim($path, '/')]);
    }

    // ── Admin / WS URLs ───────────────────────────────────────────────────────

    /** @param array<string,mixed> $params */
    public function ws(array $params = []): string
    {
        $base = $this->routeUrl('ws');
        if (empty($params)) {
            return $base;
        }
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . http_build_query($params, '', '&');
    }

    public function admin(string $section = ''): string
    {
        $base = $this->routeUrl('admin');
        if ($section === '') {
            return $base;
        }
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . 'page=' . $section;
    }
    public function adminPopupHelp(string $helpPage): string
    {
        return $this->admin('popuphelp') . '&help=' . urlencode($helpPage);
    }

    /** URL for downloading original/representative image. */
    public function actionDownload(int $id, string $part, string $pwgToken): string
    {
        return UrlService::getRootUrl() . 'index.php?/action&id=' . $id . '&part=' . $part . '&pwg_token=' . $pwgToken . '&download';
    }
    /** URL for downloading a specific image format. */
    public function actionFormat(int $formatId): string
    {
        return UrlService::getRootUrl() . 'index.php?/action&format=' . $formatId . '&download';
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** @param array<string,mixed> $params */
    private function routeUrl(string $routeName, array $params = []): string
    {
        $path = ltrim($this->router->generate($routeName, $params), '/');
        return $this->applyUrlMode($this->urls->getRootUrl(), $path);
    }

    private function applyUrlMode(string $rootUrl, string $relPath): string
    {
        return $rootUrl . 'index.php?/' . $relPath;
    }
}
