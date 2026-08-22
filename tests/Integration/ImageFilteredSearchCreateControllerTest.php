<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Api\Images\ImageFilteredSearchCreateController;
use Piwigo\Core\Kernel;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Psr\Http\Message\ResponseInterface;

/**
 * `POST /api/v1/images/searches` had zero coverage of any kind before
 * this file. Covers this file's own real reason for existing in this
 * plan: verifying `$search['fields']` is still built with the exact
 * same shape after {@see ImageFilteredSearchCreateController} and
 * {@see \Piwigo\Search\SearchService::saveSearch()} convert to/through
 * {@see \Piwigo\Search\Projection\SearchRules} -- read back via
 * {@see SearchRepository::findSavedSearchRulesByIds()} (the exact
 * boundary {@see \Piwigo\Controller\Api\History\
 * HistorySearchController} and {@see \Piwigo\Search\
 * SearchFilterRenderer} themselves read through), not just the
 * response body.
 */
final class ImageFilteredSearchCreateControllerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SearchRepository $searchRepository;

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

        $this->searchRepository = $this->resolve(SearchRepository::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        DbConnection::build()->executeStatement('DELETE FROM search');
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

    private function buildController(): ImageFilteredSearchCreateController
    {
        return new ImageFilteredSearchCreateController(
            $this->resolve(SearchService::class),
            $this->resolve(CurrentConfig::class),
            $this->resolve(UrlServiceInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(array $payload): ResponseInterface
    {
        return $this->buildController()(new ServerRequest(
            'POST',
            '/api/v1/images/searches',
            [
                'Content-Type' => 'application/json',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR)
        ));
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
     * @return array<string, mixed>
     */
    private function fetchSavedFields(string $searchUuid): array
    {
        $row = $this->searchRepository->findSavedSearchByUuid($searchUuid);
        self::assertNotNull($row);

        $fields = $row->rules['fields'] ?? null;
        self::assertIsArray($fields);

        $result = [];
        foreach ($fields as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    public function testCreateBuildsAllwordsTagsCategoryAndAuthorRules(): void
    {
        $response = $this->post([
            'allwords' => 'sunset beach',
            'allwordsMode' => 'OR',
            'tags' => [1, 2],
            'tagsMode' => 'OR',
            'categories' => [1],
            'categoriesWithsubs' => true,
            'authors' => ['jane', 'john'],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertIsString($body['searchId']);

        $fields = $this->fetchSavedFields($body['searchId']);

        self::assertSame([
            'words' => ['sunset', 'beach'],
            'mode' => 'OR',
            'fields' => ['name', 'comment', 'file', 'author', 'tags', 'cat-title', 'cat-desc'],
        ], $fields['allwords']);
        self::assertSame([
            'words' => [1, 2],
            'mode' => 'OR',
        ], $fields['tags']);
        self::assertSame([
            'words' => [1],
            'sub_inc' => true,
        ], $fields['cat']);
        self::assertSame([
            'words' => ['jane', 'john'],
        ], $fields['author']);
    }

    public function testCreateBuildsFiletypesAddedByRatiosAndRatingsRules(): void
    {
        $response = $this->post([
            'filetypes' => ['jpg', 'png'],
            'addedBy' => [1],
            'ratios' => ['Portrait'],
            'ratings' => ['3', '4'],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertIsString($body['searchId']);

        $fields = $this->fetchSavedFields($body['searchId']);

        self::assertSame(['jpg', 'png'], $fields['filetypes']);
        self::assertSame([1], $fields['added_by']);
        self::assertSame(['Portrait'], $fields['ratios']);
        self::assertSame(['3', '4'], $fields['ratings']);
    }

    public function testCreateBuildsDatePresetAndCustomRules(): void
    {
        $response = $this->post([
            'datePostedPreset' => 'custom',
            'datePostedCustom' => ['y2026'],
            'dateCreatedPreset' => '7d',
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertIsString($body['searchId']);

        $fields = $this->fetchSavedFields($body['searchId']);

        self::assertSame([
            'preset' => 'custom',
            'custom' => ['y2026'],
        ], $fields['date_posted']);
        self::assertSame([
            'preset' => '7d',
            'custom' => [],
        ], $fields['date_created']);
    }

    public function testCreateBuildsFilesizeAndDimensionBounds(): void
    {
        $response = $this->post([
            'filesizeMin' => 100,
            'filesizeMax' => 500,
            'widthMin' => 200,
            'widthMax' => 4000,
            'heightMin' => 150,
            'heightMax' => 3000,
        ]);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertIsString($body['searchId']);

        $fields = $this->fetchSavedFields($body['searchId']);

        self::assertSame(100, $fields['filesize_min']);
        self::assertSame(500, $fields['filesize_max']);
        self::assertSame(200, $fields['width_min']);
        self::assertSame(4000, $fields['width_max']);
        self::assertSame(150, $fields['height_min']);
        self::assertSame(3000, $fields['height_max']);
    }

    public function testCreateReturns422ForAnInvalidAllwordsMode(): void
    {
        $response = $this->post([
            'allwords' => 'sunset',
            'allwordsMode' => 'XOR',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testCreateReturns422WhenCustomDateProvidedWithoutACustomPreset(): void
    {
        $response = $this->post([
            'datePostedPreset' => '7d',
            'datePostedCustom' => ['y2026'],
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testCreateWithNoFieldsSavesTheEmptyDatePostedDateCreatedSeed(): void
    {
        $response = $this->post([]);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertIsString($body['searchId']);

        $fields = $this->fetchSavedFields($body['searchId']);

        // SearchRules::toArray() always emits a full {preset, custom}
        // shape once the rule object exists, unlike the old code's own
        // literal `[]` placeholder -- every real reader
        // (SearchRules::fromArray()) treats both forms identically (no
        // preset key present either way), see that class's own docblock.
        self::assertSame([
            'preset' => '',
            'custom' => [],
        ], $fields['date_posted']);
        self::assertSame([
            'preset' => '',
            'custom' => [],
        ], $fields['date_created']);
        self::assertArrayNotHasKey('allwords', $fields);
        self::assertArrayNotHasKey('tags', $fields);
    }
}
