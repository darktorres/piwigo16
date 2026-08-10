<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;

/**
 * Ws\PwgImages::filteredSearchCreate() (`pwg.images.filteredSearch.create`) --
 * was 0/154 lines covered. Pure request
 * validation + Search\SearchRepository::insertSearch() persistence, no file
 * I/O -- every branch is reachable through the WS route itself, so
 * assertions read back the persisted `rules` JSON column directly rather
 * than just checking `stat === 'ok'`.
 *
 * Three of filteredSearchCreate()'s own per-element `preg_match('/^\d+$/', ...)`
 * digit checks are NOT chased here (`tags`, `categories`, `added_by`, each
 * "Invalid parameter <name>"): all three are registered in
 * WsDefaultMethods.php with WsParamFlag::FORCE_ARRAY|WsParamType::ID, and
 * PwgServer::checkType() already rejects any non-positive-integer array
 * element at the WS layer itself -- with a *different* message ("<name>
 * must only contain positive and not null integers") -- before
 * filteredSearchCreate() ever runs. Confirmed live: a non-numeric tags[]
 * entry never reaches PwgImages.php's own check at all.
 */
final class WsImagesFilteredSearchTest extends ContractTestCase
{
    private const string METHOD = 'pwg.images.filteredSearch.create';

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    /**
     * Asserts an 'ok' response and returns its search_id.
     * @param array<string, mixed> $response
     */
    private static function searchIdOf(array $response): string
    {
        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $searchId = $result['search_id'] ?? null;
        self::assertIsString($searchId);

        return $searchId;
    }

    /** @return array<array-key, mixed> */
    private function fetchRules(string $searchUuid): array
    {
        $raw = $this->conn->fetchOne(
            'SELECT rules FROM ' . 'search' . ' WHERE search_uuid = ?',
            [$searchUuid]
        );
        self::assertIsString($raw, 'no piwigo_search row for uuid ' . $searchUuid);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Fetches the `fields` sub-array of a persisted search's rules.
     * @return array<array-key, mixed>
     */
    private function fetchFields(string $searchUuid): array
    {
        return self::arrayField($this->fetchRules($searchUuid), 'fields');
    }

    private function fetchForkedFrom(string $searchUuid): ?int
    {
        $value = $this->conn->fetchOne(
            'SELECT forked_from FROM ' . 'search' . ' WHERE search_uuid = ?',
            [$searchUuid]
        );
        if ($value === null) {
            return null;
        }
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private static function arrayField(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value, "expected array key '{$key}'");

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function stringField(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        self::assertIsString($value, "expected string key '{$key}'");

        return $value;
    }

    /**
     * MySQL's JSON binary storage format reorders object members (by key
     * length, then lexicographically) independent of the original
     * json_encode() insertion order -- ksort() both sides so these
     * still-exact-value comparisons don't depend on that internal, storage
     * engine detail.
     * @param array<string, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    private static function assertSameKeysAndValues(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    public function test_minimal_call_creates_an_empty_search(): void
    {
        $response = $this->wsAdmin(self::METHOD);
        $searchId = self::searchIdOf($response);

        $result = $response['result'];
        self::assertIsArray($result);
        /** @var array<string, mixed> $result */
        self::assertMatchesRegularExpression('/^psk-\d{8}-[a-zA-Z0-9]{10}$/', $searchId);
        self::assertTrue(str_contains(self::stringField($result, 'search_url'), $searchId));

        $rules = $this->fetchRules($searchId);
        self::assertSame(
            ['mode' => 'AND', 'fields' => ['date_posted' => [], 'date_created' => []]],
            $rules
        );
        self::assertNull($this->fetchForkedFrom($searchId));
    }

    public function test_search_id_with_invalid_pattern_returns_error(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['search_id' => 'not-a-valid-search-id']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid search_id input parameter.', $response['message']);
    }

    public function test_search_id_well_formed_but_nonexistent_returns_error(): void
    {
        // Matches the plain-digits id branch of getSearchIdPattern() but no
        // such row exists.
        $response = $this->wsAdmin(self::METHOD, ['search_id' => '99999999']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This search does not exist.', $response['message']);
    }

    public function test_search_id_forks_an_existing_search(): void
    {
        $first = $this->wsAdmin(self::METHOD, ['allwords' => 'mountain']);
        $parentUuid = self::searchIdOf($first);

        $second = $this->wsAdmin(self::METHOD, ['search_id' => $parentUuid, 'allwords' => 'lake']);
        $childUuid = self::searchIdOf($second);
        self::assertNotSame($parentUuid, $childUuid);

        $parentId = $this->conn->fetchOne(
            'SELECT id FROM ' . 'search' . ' WHERE search_uuid = ?',
            [$parentUuid]
        );
        self::assertIsNumeric($parentId);
        self::assertSame((int) $parentId, $this->fetchForkedFrom($childUuid));
    }

    public function test_allwords_defaults_mode_and_fields_when_omitted(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['allwords' => 'red fox jumps']);
        $searchId = self::searchIdOf($response);
        $fields = $this->fetchFields($searchId);
        $allwords = self::arrayField($fields, 'allwords');

        self::assertSame('AND', self::stringField($allwords, 'mode'));
        self::assertSame(
            ['name', 'comment', 'file', 'author', 'tags', 'cat-title', 'cat-desc'],
            self::arrayField($allwords, 'fields')
        );
        $words = self::arrayField($allwords, 'words');
        self::assertSame(['red', 'fox', 'jumps'], array_values($words));
    }

    public function test_allwords_mode_invalid_returns_error(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['allwords' => 'fox', 'allwords_mode' => 'XOR']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid parameter allwords_mode', $response['message']);
    }

    public function test_allwords_fields_invalid_returns_error(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'allwords' => 'fox',
            'allwords_fields' => ['name', 'bogus-field'],
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid parameter allwords_fields', $response['message']);
    }

    public function test_allwords_fields_explicit_subset_is_persisted(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'allwords' => 'fox',
            'allwords_mode' => 'OR',
            'allwords_fields' => ['name', 'tags'],
        ]);
        $searchId = self::searchIdOf($response);
        $allwords = self::arrayField($this->fetchFields($searchId), 'allwords');

        self::assertSame('OR', self::stringField($allwords, 'mode'));
        self::assertSame(['name', 'tags'], self::arrayField($allwords, 'fields'));
    }

    public function test_tags_mode_invalid_returns_error(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['tags' => [1], 'tags_mode' => 'XOR']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid parameter tags_mode', $response['message']);
    }

    public function test_tags_defaults_to_and_mode_and_is_persisted(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['tags' => [1, 2]]);
        $searchId = self::searchIdOf($response);
        $tags = self::arrayField($this->fetchFields($searchId), 'tags');

        self::assertSameKeysAndValues(['words' => [1, 2], 'mode' => 'AND'], $tags);
    }

    public function test_categories_without_withsubs_defaults_to_false(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['categories' => [1]]);
        $searchId = self::searchIdOf($response);
        $cat = self::arrayField($this->fetchFields($searchId), 'cat');

        self::assertSameKeysAndValues(['words' => [1], 'sub_inc' => false], $cat);
    }

    public function test_categories_withsubs_true_is_persisted(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['categories' => [1], 'categories_withsubs' => true]);
        $searchId = self::searchIdOf($response);
        $cat = self::arrayField($this->fetchFields($searchId), 'cat');

        self::assertSameKeysAndValues(['words' => [1], 'sub_inc' => true], $cat);
    }

    public function test_authors_are_stripped_of_html_tags(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['authors' => ['<b>Alice</b>', 'Bob']]);
        $searchId = self::searchIdOf($response);
        $author = self::arrayField($this->fetchFields($searchId), 'author');

        self::assertSameKeysAndValues(['words' => ['Alice', 'Bob'], 'mode' => 'OR'], $author);
    }

    public function test_filetypes_invalid_returns_error(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['filetypes' => ['jpg', 'not a type!']]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid parameter filetypes', $response['message']);
    }

    public function test_filetypes_valid_are_persisted(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['filetypes' => ['jpg', 'png']]);
        $searchId = self::searchIdOf($response);
        $fields = $this->fetchFields($searchId);

        self::assertSame(['jpg', 'png'], self::arrayField($fields, 'filetypes'));
    }

    public function test_added_by_is_persisted(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['added_by' => [1]]);
        $searchId = self::searchIdOf($response);
        $fields = $this->fetchFields($searchId);

        self::assertSame([1], self::arrayField($fields, 'added_by'));
    }

    public function test_date_posted_preset_invalid_returns_error(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['date_posted_preset' => '99d']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid parameter date_posted_preset', $response['message']);
    }

    public function test_date_posted_preset_custom_without_custom_values_returns_error(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['date_posted_preset' => 'custom']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('date_posted_custom is missing', $response['message']);
    }

    public function test_date_posted_custom_without_preset_custom_returns_error(): void
    {
        // date_posted_preset omitted entirely -- 'preset' key never gets set,
        // so the isset() guard on the date_posted_custom branch fails.
        $response = $this->wsAdmin(self::METHOD, ['date_posted_custom' => ['y2026']]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('date_posted_custom provided date_posted_preset is not custom', $response['message']);
    }

    public function test_date_posted_custom_accepts_year_month_and_day_formats(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'date_posted_preset' => 'custom',
            'date_posted_custom' => ['y2026', 'm2026-07', 'd2026-07-15'],
        ]);
        $searchId = self::searchIdOf($response);
        $datePosted = self::arrayField($this->fetchFields($searchId), 'date_posted');

        self::assertSame('custom', self::stringField($datePosted, 'preset'));
        self::assertSame(['y2026', 'm2026-07', 'd2026-07-15'], self::arrayField($datePosted, 'custom'));
    }

    public function test_date_posted_custom_rejects_invalid_month(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'date_posted_preset' => 'custom',
            'date_posted_custom' => ['m2026-13'],
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('date_posted_custom, invalid option m2026-13', $response['message']);
    }

    public function test_date_posted_custom_rejects_invalid_day_for_month(): void
    {
        // February 2026 is not a leap year -- day 30 doesn't exist.
        $response = $this->wsAdmin(self::METHOD, [
            'date_posted_preset' => 'custom',
            'date_posted_custom' => ['d2026-02-30'],
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('date_posted_custom, invalid option d2026-02-30', $response['message']);
    }

    public function test_date_posted_custom_rejects_unrecognized_prefix(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'date_posted_preset' => 'custom',
            'date_posted_custom' => ['x2026'],
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('date_posted_custom, invalid option x2026', $response['message']);
    }

    public function test_date_created_preset_invalid_returns_error(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['date_created_preset' => '99d']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid parameter date_created_preset', $response['message']);
    }

    public function test_date_created_custom_accepts_day_format(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'date_created_preset' => 'custom',
            'date_created_custom' => ['d2026-01-15'],
        ]);
        $searchId = self::searchIdOf($response);
        $dateCreated = self::arrayField($this->fetchFields($searchId), 'date_created');

        self::assertSame('custom', self::stringField($dateCreated, 'preset'));
        self::assertSame(['d2026-01-15'], self::arrayField($dateCreated, 'custom'));
    }

    public function test_ratios_invalid_returns_error(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['ratios' => ['square', 'not valid!']]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid parameter ratios', $response['message']);
    }

    public function test_ratios_valid_are_persisted(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['ratios' => ['square', 'panorama']]);
        $searchId = self::searchIdOf($response);
        $fields = $this->fetchFields($searchId);

        self::assertSame(['square', 'panorama'], self::arrayField($fields, 'ratios'));
    }

    public function test_expert_string_is_persisted(): void
    {
        $response = $this->wsAdmin(self::METHOD, ['expert' => 'width > 800']);
        $searchId = self::searchIdOf($response);
        $fields = $this->fetchFields($searchId);

        self::assertSame(['string' => 'width > 800'], self::arrayField($fields, 'expert'));
    }

    public function test_ratings_are_persisted_when_rate_is_enabled(): void
    {
        // rate=true in the fixture config (CurrentConfig::rateEnabled() default).
        $response = $this->wsAdmin(self::METHOD, ['ratings' => ['4', '5']]);
        $searchId = self::searchIdOf($response);
        $fields = $this->fetchFields($searchId);

        self::assertSame(['4', '5'], self::arrayField($fields, 'ratings'));
    }

    public function test_size_range_filters_are_persisted(): void
    {
        $response = $this->wsAdmin(self::METHOD, [
            'filesize_min' => 100,
            'filesize_max' => 5000,
            'width_min' => 200,
            'width_max' => 1600,
            'height_min' => 150,
            'height_max' => 1200,
        ]);
        $searchId = self::searchIdOf($response);
        $fields = $this->fetchFields($searchId);

        self::assertSame(100, $fields['filesize_min']);
        self::assertSame(5000, $fields['filesize_max']);
        self::assertSame(200, $fields['width_min']);
        self::assertSame(1600, $fields['width_max']);
        self::assertSame(150, $fields['height_min']);
        self::assertSame(1200, $fields['height_max']);
    }
}
