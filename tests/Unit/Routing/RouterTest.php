<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Piwigo\Routing\Router;
use Piwigo\Routing\RouteResult;

/**
 * Unit tests for Router — covers dispatch and generation.
 *
 * Uses the real config/routes.php so that route-table regressions are caught.
 * No DB, no HTTP bootstrap required.
 */
final class RouterTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private Router $router;

    #[\Override]
    protected function setUp(): void
    {
        $this->router = new Router(dirname(__DIR__, 3) . '/config/routes.php');
    }

    // ── Dispatch — found ──────────────────────────────────────────────────────

    public function test_gallery_root_resolves(): void
    {
        $result = $this->router->dispatch('GET', '/');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertStringContainsString('GalleryController', $result->handler);
    }

    public function test_category_path_resolves_with_rest_arg(): void
    {
        $result = $this->router->dispatch('GET', '/category/12-foo/start-24');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertStringContainsString('GalleryController', $result->handler);
        self::assertSame('12-foo/start-24', $result->args['rest']);
    }

    public function test_picture_path_resolves(): void
    {
        $result = $this->router->dispatch('GET', '/picture/42-my-photo');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertStringContainsString('PictureController', $result->handler);
        self::assertSame('42-my-photo', $result->args['rest']);
    }

    public function test_ws_bare_resolves(): void
    {
        $result = $this->router->dispatch('POST', '/ws');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertStringContainsString('WsController', $result->handler);
        self::assertSame('', $result->args['rest']);
    }

    public function test_ws_with_subpath_resolves(): void
    {
        $result = $this->router->dispatch('GET', '/ws/openapi.json');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertStringContainsString('WsController', $result->handler);
        self::assertSame('/openapi.json', $result->args['rest']);
    }

    public function test_admin_bare_resolves(): void
    {
        $result = $this->router->dispatch('GET', '/admin');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertStringContainsString('AdminController', $result->handler);
        self::assertSame('', $result->args['rest']);
    }

    public function test_admin_with_section_resolves(): void
    {
        $result = $this->router->dispatch('GET', '/admin/batch_manager');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertSame('/batch_manager', $result->args['rest']);
    }

    public function test_identification_get_resolves(): void
    {
        $result = $this->router->dispatch('GET', '/identification');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertStringContainsString('IdentificationController', $result->handler);
    }

    public function test_identification_post_resolves(): void
    {
        $result = $this->router->dispatch('POST', '/identification');
        self::assertSame(RouteResult::FOUND, $result->status);
    }

    public function test_favorites_resolves(): void
    {
        $result = $this->router->dispatch('GET', '/favorites');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertStringContainsString('GalleryController', $result->handler);
    }

    public function test_feed_resolves(): void
    {
        $result = $this->router->dispatch('GET', '/feed');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertStringContainsString('FeedController', $result->handler);
    }

    public function test_image_derivative_resolves(): void
    {
        $result = $this->router->dispatch('GET', '/i/thumbnails/my-photo.jpg');
        self::assertSame(RouteResult::FOUND, $result->status);
        self::assertStringContainsString('ImageDerivativeController', $result->handler);
        self::assertSame('thumbnails/my-photo.jpg', $result->args['rest']);
    }

    // ── Dispatch — not found / method not allowed ─────────────────────────────

    public function test_unknown_path_returns_not_found(): void
    {
        $result = $this->router->dispatch('GET', '/nonexistent-section');
        self::assertSame(RouteResult::NOT_FOUND, $result->status);
        self::assertFalse($result->isFound());
    }

    public function test_wrong_method_returns_method_not_allowed(): void
    {
        // Gallery root only allows GET
        $result = $this->router->dispatch('POST', '/');
        self::assertSame(RouteResult::METHOD_NOT_ALLOWED, $result->status);
    }

    // ── Generation ────────────────────────────────────────────────────────────

    public function test_generate_gallery_root(): void
    {
        $url = $this->router->generate('gallery');
        self::assertSame('/', $url);
    }

    public function test_generate_ws(): void
    {
        $url = $this->router->generate('ws', ['rest' => '']);
        self::assertSame('/ws', $url);
    }

    public function test_generate_admin_with_section(): void
    {
        $url = $this->router->generate('admin', ['rest' => '/batch_manager']);
        self::assertSame('/admin/batch_manager', $url);
    }

    public function test_generate_identification(): void
    {
        $url = $this->router->generate('identification');
        self::assertSame('/identification', $url);
    }
}
