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
use Piwigo\Controller\Api\History\HistorySearchController;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\History\HistoryService;
use Piwigo\Http\AdminGuard;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Search\SearchRepository;
use Piwigo\Tag\TagService;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;

/**
 * Zero coverage of any kind existed for this controller before this
 * file. Covers the one thing the SearchRules conversion of
 * {@see HistorySearchController::buildSearchDetails()} needs a safety
 * net for: every real field it reads (`allwords`/`tags`/`cat`/
 * `author`/`addedBy`/`filetypes`), plus the pre-existing `datePosted`
 * display bug (always null, see that method's own comment) faithfully
 * preserved by the conversion, not fixed.
 *
 * Fixture users: id=1 is `fixture_admin`/webmaster, id=3 is
 * `regular_user`/normal. `logVisit()` is called while CurrentUser is
 * the normal user (its own `isLoggingAllowed()` reads
 * `CurrentConfig::$historyAdmin`, a `private(set)` field this test
 * can't override, for an admin actor) -- CurrentUser only switches to
 * the admin fixture user afterwards, for the controller's own
 * AdminGuard check.
 */
final class HistorySearchControllerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private HistoryService $historyService;

    private SearchRepository $searchRepository;

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

        $currentConfig->logConf = true;

        $this->historyService = $this->resolve(HistoryService::class);
        $this->searchRepository = $this->resolve(SearchRepository::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbConnection::build()->executeStatement('DELETE FROM history');
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

    private function seedUser(int $id, string $status): void
    {
        $this->resolve(CurrentUser::class)->set(User::fromUserArray([
            'id' => $id,
            'status' => $status,
            'username' => $status === 'webmaster' ? 'fixture_admin' : 'regular_user',
        ]));
    }

    private function buildController(): HistorySearchController
    {
        return new HistorySearchController(
            new AdminGuard($this->resolve(AccessControl::class)),
            $this->historyService,
            $this->resolve(CategoryService::class),
            $this->resolve(TagService::class),
            $this->resolve(UserService::class),
            $this->resolve(ImageRepository::class),
            $this->resolve(ImageStdParams::class),
            $this->resolve(HtmlRenderingInterface::class),
            $this->resolve(UrlServiceInterface::class),
            $this->resolve(EventDispatcher::class),
            $this->resolve(Translator::class),
            $this->searchRepository,
            $this->resolve(CurrentConfig::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function findLineForSearchId(array $data, int $searchId): array
    {
        $lines = $data['lines'] ?? null;
        self::assertIsArray($lines);

        foreach ($lines as $line) {
            self::assertIsArray($line);
            if (($line['searchId'] ?? null) === $searchId) {
                $result = [];
                foreach ($line as $k => $v) {
                    self::assertIsString($k);
                    $result[$k] = $v;
                }

                return $result;
            }
        }

        self::fail('No history line found for search id ' . $searchId);
    }

    public function testBuildSearchDetailsExposesEveryFilterFromTheSavedSearchRules(): void
    {
        $searchId = $this->searchRepository->insertSavedSearch([
            'fields' => [
                'allwords' => [
                    'words' => ['sunset'],
                    'mode' => 'AND',
                    'fields' => ['name'],
                ],
                'tags' => [
                    'words' => [1],
                    'mode' => 'AND',
                ],
                'cat' => [
                    'words' => [1],
                    'sub_inc' => false,
                ],
                'author' => [
                    'words' => ['jane'],
                ],
                'added_by' => [1],
                'filetypes' => ['jpg'],
                'date_posted' => [
                    'preset' => '7d',
                ],
            ],
        ]);

        $this->seedUser(3, 'normal');
        $this->historyService->logVisit(section: 'search', searchId: $searchId);

        $this->seedUser(1, 'webmaster');
        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/history/search'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        $line = $this->findLineForSearchId($body, $searchId);

        $details = $line['searchDetails'];
        self::assertIsArray($details);

        self::assertSame(['sunset'], $details['allwords']);
        self::assertIsArray($details['tags']);
        self::assertIsArray($details['cat']);
        self::assertSame(['jane'], $details['author']);
        self::assertIsArray($details['addedBy']);
        self::assertSame(['jpg'], $details['filetypes']);
        // Pre-existing display bug, faithfully preserved -- date_posted is
        // never a plain string in real data, so this always renders null
        // regardless of the real preset.
        self::assertNull($details['datePosted']);
    }

    public function testBuildSearchDetailsReturnsNullFieldsForRulesWithNoMatchingFilters(): void
    {
        $searchId = $this->searchRepository->insertSavedSearch([
            'fields' => [],
        ]);

        $this->seedUser(3, 'normal');
        $this->historyService->logVisit(section: 'search', searchId: $searchId);

        $this->seedUser(1, 'webmaster');
        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/history/search'));

        $body = $this->decode($response);
        $line = $this->findLineForSearchId($body, $searchId);

        $details = $line['searchDetails'];
        self::assertIsArray($details);

        self::assertNull($details['allwords']);
        self::assertNull($details['tags']);
        self::assertNull($details['cat']);
        self::assertNull($details['author']);
        self::assertNull($details['addedBy']);
        self::assertNull($details['filetypes']);
        self::assertNull($details['datePosted']);
    }

    public function testALineWithNoSearchIdHasNullSearchDetails(): void
    {
        $this->seedUser(3, 'normal');
        $this->historyService->logVisit(section: 'tags');

        $this->seedUser(1, 'webmaster');
        $response = $this->buildController()(new ServerRequest('GET', '/api/v1/history/search'));

        $body = $this->decode($response);
        $lines = $body['lines'];
        self::assertIsArray($lines);

        $found = false;
        foreach ($lines as $line) {
            self::assertIsArray($line);
            if (array_key_exists('searchId', $line) && $line['searchId'] === null) {
                $found = true;
                self::assertNull($line['searchDetails']);
            }
        }

        self::assertTrue($found, 'Expected at least one history line with no search id');
    }
}
