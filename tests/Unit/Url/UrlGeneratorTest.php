<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Url;

use PHPUnit\Framework\TestCase;
use Piwigo\Routing\Router;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;

/**
 * Unit tests for UrlGenerator.
 *
 * Tests the PSR-15 named-route methods (identification, register, …) and the
 * routed admin/ws methods without any DB bootstrap.
 *
 * Config defaults (php_extension_in_urls=true, question_mark_in_urls=true) are
 * used because the Config store is not initialised in unit-test context.
 * UrlService::getRootUrl() falls back to PHPWG_ROOT_PATH when neither a
 * setMakeFullUrl override nor a SectionContext rootPath is set; assertions
 * use assertStringContainsString so they're root-path-agnostic.
 */
final class UrlGeneratorTest extends TestCase
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    private Router $router;
    /** @psalm-suppress PropertyNotSetInConstructor */
    private UrlService $urls;
    /** @psalm-suppress PropertyNotSetInConstructor */
    private UrlGenerator $gen;

    #[\Override]
    protected function setUp(): void
    {
        $this->router = new Router(dirname(__DIR__, 3) . '/config/routes.php');
        // UrlGenerator only calls UrlService methods that don't touch the injected
        // deps (Connection/StringUtil/etc.) — skip the constructor to avoid
        // mocking final classes the test never exercises.
        $this->urls   = new \ReflectionClass(UrlService::class)->newInstanceWithoutConstructor();
        $this->gen    = new UrlGenerator($this->router, $this->urls);
    }

    // ── Named PSR-15 routes ───────────────────────────────────────────────────

    public function test_identification_contains_segment(): void
    {
        self::assertStringContainsString('identification', $this->gen->identification());
    }

    public function test_register_contains_segment(): void
    {
        self::assertStringContainsString('register', $this->gen->register());
    }

    public function test_password_contains_segment(): void
    {
        self::assertStringContainsString('password', $this->gen->password());
    }

    public function test_profile_contains_segment(): void
    {
        self::assertStringContainsString('profile', $this->gen->profile());
    }

    public function test_comments_contains_segment(): void
    {
        self::assertStringContainsString('comments', $this->gen->comments());
    }

    public function test_notification_contains_segment(): void
    {
        self::assertStringContainsString('notification', $this->gen->notification());
    }

    public function test_feed_contains_segment(): void
    {
        self::assertStringContainsString('feed', $this->gen->feed());
    }

    public function test_random_contains_segment(): void
    {
        self::assertStringContainsString('random', $this->gen->random());
    }

    public function test_named_routes_are_distinct(): void
    {
        $urls = [
            $this->gen->identification(),
            $this->gen->register(),
            $this->gen->password(),
            $this->gen->profile(),
            $this->gen->comments(),
            $this->gen->notification(),
            $this->gen->feed(),
            $this->gen->random(),
        ];
        self::assertSame(count($urls), count(array_unique($urls)), 'All named route URLs must be distinct');
    }

    // ── URL-mode prefix (default config: php_extension=true, question_mark=true) ─

    public function test_named_route_carries_url_mode_prefix(): void
    {
        // With default config the URL should use the index.php?/ prefix
        $url = $this->gen->identification();
        self::assertStringContainsString('index.php', $url);
    }

    // ── Image route ───────────────────────────────────────────────────────────

    public function test_image_embeds_path(): void
    {
        $url = $this->gen->image('thumbnails/my-photo.jpg');
        self::assertStringContainsString('thumbnails/my-photo.jpg', $url);
    }

    public function test_image_strips_leading_slash(): void
    {
        $withSlash    = $this->gen->image('/thumbnails/foo.jpg');
        $withoutSlash = $this->gen->image('thumbnails/foo.jpg');
        self::assertSame($withSlash, $withoutSlash);
    }

    // ── Admin URL ────────────────────────────────────────────────────────────

    public function test_admin_no_section_has_no_page_param(): void
    {
        $url = $this->gen->admin();
        self::assertStringContainsString('/admin', $url);
        self::assertStringNotContainsString('page=', $url);
    }

    public function test_admin_section_appended_as_page_param(): void
    {
        $url = $this->gen->admin('batch_manager');
        self::assertStringContainsString('/admin', $url);
        self::assertStringContainsString('page=batch_manager', $url);
    }

    public function test_admin_sections_are_distinct(): void
    {
        self::assertNotSame(
            $this->gen->admin('batch_manager'),
            $this->gen->admin('configuration'),
        );
    }

    // ── WS URL ───────────────────────────────────────────────────────────────

    public function test_ws_no_params_has_no_query_string(): void
    {
        $url = $this->gen->ws();
        self::assertStringContainsString('/ws', $url);
        self::assertStringEndsWith('/ws', $url);
    }

    public function test_ws_params_added_as_query_string(): void
    {
        $url = $this->gen->ws(['method' => 'pwg.images.list', 'format' => 'json']);
        self::assertStringContainsString('/ws', $url);
        self::assertStringContainsString('method=pwg.images.list', $url);
        self::assertStringContainsString('format=json', $url);
    }
}
