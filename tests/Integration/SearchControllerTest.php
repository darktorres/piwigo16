<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\SearchController;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchService;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Validation\InputValidator;
use RuntimeException;
use Throwable;

/**
 * __invoke()'s own cat_id === null routing (a real, "0" is
 * syntactically-digits-but-non-positive scenario -- see
 * SearchQueryRequest::$catId's own docblock) must land on
 * HtmlRenderingInterface::pageNotFound(), never fatalError() -- collapsing
 * both into a bare null check would reclassify "that album doesn't exist"
 * as a hacking attempt. This class had zero non-Browser coverage before
 * this test.
 *
 * HtmlService::pageNotFound() just forwards to
 * RedirectServiceInterface::redirectHtml(); HtmlService::fatalError()
 * throws Piwigo\Http\ResponseReadyException directly, with no
 * RedirectServiceInterface involved at all -- so substituting only
 * $redirectService with a capturing fake (real HtmlRenderingInterface
 * otherwise) is enough to observe which one ran, same
 * capture-then-throw-a-marker convention as
 * CategoryAdminServiceFakeCapturingRedirectService.
 */
final class SearchControllerTestFakeCapturingRedirectService implements RedirectServiceInterface
{
    #[Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        throw new LogicException('not used by this test\'s own pageNotFound() call');
    }

    #[Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        throw new RuntimeException('SEARCH_CONTROLLER_TEST_PAGE_NOT_FOUND_MARKER status=' . $status);
    }

    #[Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        throw new LogicException('not used by this test\'s own pageNotFound() call');
    }
}

final class SearchControllerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = $this->resolve(CurrentConfig::class);
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        Kernel::boot();
    }

    #[Override]
    protected function tearDown(): void
    {
        unset($_GET['cat_id']);
        Kernel::reset();
        parent::tearDown();
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function resolve(string $class): object
    {
        $instance = Kernel::container()->get($class);
        if (! $instance instanceof $class) {
            throw new LogicException('Container returned an unexpected type for ' . $class);
        }

        return $instance;
    }

    private function buildController(RedirectServiceInterface $redirectService): SearchController
    {
        return new SearchController(
            $this->resolve(Lang::class),
            $this->resolve(AccessControl::class),
            $redirectService,
            $this->resolve(UrlServiceInterface::class),
            $this->resolve(EventDispatcher::class),
            $this->resolve(CurrentUser::class),
            $this->resolve(SearchService::class),
            $this->resolve(PermissionService::class),
            $this->resolve(PreferencesService::class),
            $this->resolve(CategoryService::class),
            $this->resolve(TagService::class),
            $this->resolve(ImageService::class),
            $this->resolve(HtmlRenderingInterface::class),
            $this->resolve(CurrentConfig::class),
            $this->resolve(InputValidator::class),
        );
    }

    public function testInvokeRoutesANonPositiveCatIdToPageNotFoundNotFatalError(): void
    {
        $_GET['cat_id'] = '0';

        $redirectService = new SearchControllerTestFakeCapturingRedirectService();
        $controller = $this->buildController($redirectService);

        $caught = null;
        try {
            $controller(new ServerRequest('GET', '/search.php?cat_id=0'));
        } catch (Throwable $e) {
            $caught = $e;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertNotInstanceOf(ResponseReadyException::class, $caught);
        self::assertStringContainsString('SEARCH_CONTROLLER_TEST_PAGE_NOT_FOUND_MARKER', $caught->getMessage());
    }
}
