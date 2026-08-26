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
use Piwigo\Db\DbConnection;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Tag\TagService;
use Piwigo\Tests\Support\DbTransactionTestOverride;
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

/**
 * Captures the URL {@see \Piwigo\Controller\SearchController::__invoke()}'s
 * own final `redirect()` call passes -- same capture-then-throw-a-marker
 * convention as {@see SearchControllerTestFakeCapturingRedirectService}
 * above, kept as a separate class so this one's `redirect()` override
 * doesn't change that class's own existing pageNotFound-scoped
 * expectations.
 */
final class SearchControllerTestFakeCapturingRedirect implements RedirectServiceInterface
{
    public ?string $capturedUrl = null;

    #[Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        throw new LogicException('not used by this test');
    }

    #[Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        throw new LogicException('not used by this test');
    }

    #[Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        $this->capturedUrl = $url;
        throw new RuntimeException('SEARCH_CONTROLLER_TEST_REDIRECT_MARKER');
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
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

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
        DbConnection::build()->executeStatement('DELETE FROM search');
        Kernel::reset();
        DbTransactionTestOverride::rollback();
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

    /**
     * @return array<string, mixed>
     */
    private function invokeAndFetchSavedFields(ServerRequest $request): array
    {
        $redirectService = new SearchControllerTestFakeCapturingRedirect();
        $controller = $this->buildController($redirectService);

        $caught = null;
        try {
            $controller($request);
        } catch (Throwable $e) {
            $caught = $e;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertStringContainsString('SEARCH_CONTROLLER_TEST_REDIRECT_MARKER', $caught->getMessage());
        self::assertIsString($redirectService->capturedUrl);

        // makeIndexUrl(['section' => 'search', 'search' => $uuid]) embeds
        // the uuid as a path segment (`.../search/<uuid>`), not a query
        // param -- see UrlService::makeSectionInUrl()'s own 'search' case.
        $matched = preg_match('#/search/([^/?]+)#', $redirectService->capturedUrl, $matches);
        self::assertSame(1, $matched, 'redirected URL must contain a /search/<uuid> segment');
        $searchUuid = $matches[1];

        $searchRepository = $this->resolve(SearchRepository::class);
        $search = $searchRepository->findSavedSearchByUuid($searchUuid);
        self::assertNotNull($search);

        $fields = $search->rules['fields'] ?? null;
        self::assertIsArray($fields);

        $result = [];
        foreach ($fields as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * A fresh CurrentConfig's own filtersViews falls back to
     * defaultFiltersViews (lastFiltersConf is null -> false), which
     * always forces $fields = $default_fields regardless of the current
     * user's own status -- out of the box, exactly `words`/
     * `creation_date`/`album` (renamed to `allwords`/`date_created`/
     * `cat`) default to on, see CurrentConfig::DEFAULT_FILTERS_VIEWS.
     */
    public function testInvokeSeedsExactlyTheDefaultActiveFilters(): void
    {
        $fields = $this->invokeAndFetchSavedFields(new ServerRequest('GET', '/search.php'));

        self::assertSame([
            'words' => [],
            'mode' => 'AND',
            'fields' => ['file', 'name', 'comment', 'tags', 'author', 'cat-title', 'cat-desc'],
        ], $fields['allwords']);
        self::assertSame([
            'words' => [],
            'sub_inc' => true,
        ], $fields['cat']);
        self::assertSame([
            'preset' => '',
            'custom' => [],
        ], $fields['date_created']);
        self::assertArrayNotHasKey('tags', $fields);
        self::assertArrayNotHasKey('author', $fields);
    }

    public function testInvokeSplitsARealQIntoAllwordsWords(): void
    {
        $_GET['q'] = 'sunset beach';

        $fields = $this->invokeAndFetchSavedFields(new ServerRequest('GET', '/search.php?q=sunset+beach'));

        self::assertIsArray($fields['allwords']);
        self::assertSame(['sunset', 'beach'], $fields['allwords']['words']);

        unset($_GET['q']);
    }

    public function testInvokeSeedsCatFromARealCatId(): void
    {
        $_GET['cat_id'] = '1';

        $fields = $this->invokeAndFetchSavedFields(new ServerRequest('GET', '/search.php?cat_id=1'));

        self::assertIsArray($fields['cat']);
        self::assertSame(['1'], $fields['cat']['words']);
    }
}
