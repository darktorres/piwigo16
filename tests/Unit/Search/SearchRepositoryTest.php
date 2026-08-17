<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\SqlCondition;
use Piwigo\Search\Projection\CategoryIdUppercats;
use Piwigo\Search\Projection\Search;
use Piwigo\Search\SearchRepository;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * Piwigo\Search\SearchRepository -- has its own dedicated
 * tests/Integration/SearchRepositoryTest.php; this ports most of its tests
 * down to the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern. `search` starts empty in the fixture.
 *
 * findImageIdsMatching()/expressionBuilder() aren't covered by this
 * repository's own Integration spec -- both are exercised transitively
 * via SearchService's own tests (not yet ported to Unit).
 *
 * Same fixture shape as CategoryRepositoryTest/SectionRepositoryTest:
 * images 1-5 (image_category assigns 1,2,3 to category 1 and 4,5 to
 * category 2).
 *
 * Confirmed-equivalent mutations, not individually tested: every
 * `is_numeric(...) ? (int) ... : default` cast after
 * getSingleScalarResult()/fetchFirstColumn() (countSavedSearchByUuid(),
 * findImageIdsByRawWhere()/findImageIdsForRegularSearch()) and every
 * `is_array($row) || ! is_numeric/instanceof` guard over a plain (non-VO) or
 * array-hydrated-VO column (findSavedSearchRulesByIds(),
 * findCategoryIdsAndUppercats()) are unreachable on this driver, same root
 * cause documented throughout this project's other Unit-suite files;
 * `$entity->id ?? 0` in toProjection()/insertSavedSearch() is unreachable --
 * a real, already-persisted or just-flushed SavedSearchEntity always has a
 * real autoincrement id; findSavedSearchRulesByIds()'s own
 * `if ($ids === []) { return []; }` early return is unobservable if skipped
 * -- confirmed live (sed-mutate-and-rerun: disabling it still returns `[]`
 * for an empty `$ids`, DBAL's own `ArrayParameterType` expansion of an empty
 * array already matches nothing on this driver, same root cause as
 * PermalinkRepositoryTest.php's own findPermalinkMatches() finding).
 *
 * §14: findIdsByClause()/findRowsByClause()/quote() (a free-form
 * table/column-string escape hatch, and a genuinely dead helper -- zero
 * production callers even before this pass) are retired in favor of
 * findImageIdsByRawWhere()/findImageIdsForRegularSearch()/
 * findTagRowsByRawWhere()/findCategoryRowsByRawWhere(), each naming its own
 * real table and explicit column list. The `tsv_`-prefix key-stripping test
 * that used to live here is gone with it: an explicit column list can't
 * select a `tsv_*` generated column in the first place, so there is nothing
 * left to strip or to test.
 */
function searchTestRepo(): SearchRepository
{
    return new SearchRepository(EntityManagerFactory::build(DbConnection::build()));
}

function searchTestUuid(): string
{
    // Random, not the Integration original's own fixed suffixes -- this
    // suite's DB persists across runs (no per-class fixture reload the
    // way IntegrationTestCase gets), so a fixed uuid collides with a
    // leftover row from an earlier run instead of a fresh insert.
    // search_uuid is a real, 23-char-max column -- 'psk-rt' (6) + 16 hex
    // chars (8 random bytes) fits with no room left for more.
    //
    // 'rt' immediately after 'psk-' (not a digit) is deliberate, not
    // decorative: SearchServiceTest.php's own real production-shaped
    // uuids ('psk-{8-digit date}-{10 chars}', both hand-written literals
    // and SearchService::getAvailableSearchUuid()'s own real output)
    // start matching this file's OWN cleanup pattern below whenever it
    // was the broader `LIKE 'psk-%'` -- composer test's parallel runner
    // puts different test FILES in different worker processes against
    // the SAME real, shared DB, so one file's afterEach() firing mid-way
    // through another file's still-in-flight insert-then-read-back test
    // silently deleted the other's row before it could be read.
    // Confirmed live: this collision produced real, intermittent
    // failures in SearchServiceTest.php's own getSearchArray()/
    // getSearchInfo() tests. A structurally distinct shape (never
    // digit-8-then-hyphen at this position) makes both files' own
    // patterns mutually exclusive regardless of run order.
    return 'psk-rt' . bin2hex(random_bytes(8));
}

afterEach(function (): void {
    DbConnection::build()->executeStatement('DELETE FROM search' . " WHERE search_uuid LIKE 'psk-rt%'");
});

test('findSavedSearchByUuid() returns null for no match', function (): void {
    expect(searchTestRepo()->findSavedSearchByUuid('no-such-uuid'))
        ->toBeNull();
});

test('findSavedSearchByUuid() returns the matching row', function (): void {
    $repo = searchTestRepo();
    $uuid = searchTestUuid();
    $repo->insertSavedSearch([
        'q' => 'nature',
    ], '2026-07-12 00:00:00', 1, $uuid, null);

    $row = $repo->findSavedSearchByUuid($uuid);

    expect($row)
        ->not->toBeNull();
    if (! $row instanceof Search) {
        throw new RuntimeException('unreachable');
    }
    expect($row->searchUuid)
        ->toBe($uuid);
});

test('findSavedSearchByUuid() filters out a numeric-string rules key on decode', function (): void {
    // Doctrine's native json Type decodes via json_decode($v, true) --
    // PHP auto-coerces a numeric JSON object key ("5") into a real PHP
    // int array key, not a string one, so this repository's own
    // string-keys-only filter is what actually drops it.
    // insertSavedSearch()'s own real contract (like every real caller,
    // SearchService::saveSearch()) only ever writes string-keyed rules --
    // a raw insert of the hand-built JSON is the only way to reach this
    // branch, same "bypass the typed method" technique as the sibling
    // null-rules test below.
    $conn = DbConnection::build();
    $uuid = searchTestUuid();
    $conn->executeStatement(
        'INSERT INTO search (rules, created_on, created_by, search_uuid, forked_from) VALUES (?, ?, ?, ?, NULL)',
        ['{"5":"numeric-key-value","q":"nature"}', '2026-07-12 00:00:00', 1, $uuid]
    );

    $row = searchTestRepo()
        ->findSavedSearchByUuid($uuid);

    expect($row)
        ->not->toBeNull();
    if (! $row instanceof Search) {
        throw new RuntimeException('unreachable');
    }
    expect($row->rules)
        ->toBe([
            'q' => 'nature',
        ]);
});

test('findSavedSearchByUuid() maps a null created_on to null, not the entity instance', function (): void {
    $repo = searchTestRepo();
    $uuid = searchTestUuid();
    $repo->insertSavedSearch([
        'q' => 'nature',
    ], null, 1, $uuid, null);

    $row = $repo->findSavedSearchByUuid($uuid);

    expect($row)
        ->not->toBeNull();
    if (! $row instanceof Search) {
        throw new RuntimeException('unreachable');
    }
    expect($row->createdOn)
        ->toBeNull();
});

test('findImageIdsByRawWhere() returns a list of ints', function (): void {
    // Bounded to the fixture's own 5 ids, not a bare 'id > 0' -- several
    // OTHER Unit-suite files insert a disposable image (a real, higher
    // auto-increment id) for the span of their own test, which an
    // unbounded condition here could catch mid-test under --parallel.
    $ids = searchTestRepo()
        ->findImageIdsByRawWhere('id > ? AND id <= ?', [0, 5]);
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3, 4, 5]);
});

test('findImageIdsByRawWhere() returns empty for no match', function (): void {
    // Structurally impossible (id > 10 and id < 10 can never both hold),
    // not a bare high threshold like 'id > 99999' -- several OTHER
    // Unit-suite files deliberately use ids well above that (e.g.
    // ImageServiceTest.php's randomized 600000-699999 range) specifically
    // to stay clear of the fixture's own low ids, which an
    // unbounded-above condition here could catch mid-test under
    // --parallel (confirmed live: this exact test has raced against
    // ImageServiceTest.php this way). This condition matches nothing
    // regardless of what any row's real id ever is, so no threshold can
    // ever need raising again.
    expect(searchTestRepo()->findImageIdsByRawWhere('id > ? AND id < ?', [10, 10]))->toBe([]);
});

test('findImageIdsByRawWhere() orders by the given order body', function (): void {
    $ids = searchTestRepo()
        ->findImageIdsByRawWhere('id > ? AND id <= ?', [0, 5], 'id DESC');

    expect($ids)
        ->toBe([5, 4, 3, 2, 1]);
});

test('findTagRowsByRawWhere() returns full rows', function (): void {
    $rows = searchTestRepo()
        ->findTagRowsByRawWhere('name = ?', ['nature']);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]['id'])->toBe(1)
        ->and($rows[0]['name'])->toBe('nature');
});

test('findTagRowsByRawWhere() returns empty for no match', function (): void {
    expect(searchTestRepo()->findTagRowsByRawWhere('name = ?', ['no-such-tag']))->toBe([]);
});

test('findCategoryRowsByRawWhere() returns full rows', function (): void {
    $rows = searchTestRepo()
        ->findCategoryRowsByRawWhere('id = ?', [1]);

    expect($rows)
        ->toHaveCount(1)
        ->and($rows[0]['id'])->toBe(1)
        ->and($rows[0]['name'])->toBe('Sample Album');
});

test('findCategoryRowsByRawWhere() returns empty for no match', function (): void {
    expect(searchTestRepo()->findCategoryRowsByRawWhere('id = ?', [99999]))->toBe([]);
});

test('findImageIdsForRegularSearch() joins image_category and groups by id when requested', function (): void {
    // Fixture: images 1-3 belong to category 1, 4-5 to category 2 (see this
    // file's own header docblock) -- category_id only resolves at all once
    // the join is applied, and GROUP BY id keeps one row per image even
    // though the join can fan out per membership.
    $ids = searchTestRepo()
        ->findImageIdsForRegularSearch('category_id = ?', [1], true, '');
    sort($ids);

    expect($ids)
        ->toBe([1, 2, 3]);
});

test('findImageIdsForRegularSearch() omits the join when not requested', function (): void {
    $ids = searchTestRepo()
        ->findImageIdsForRegularSearch('id > ? AND id <= ?', [0, 5], false, 'id DESC');

    expect($ids)
        ->toBe([5, 4, 3, 2, 1]);
});

test('countSavedSearchByUuid() returns zero for unknown uuid', function (): void {
    expect(searchTestRepo()->countSavedSearchByUuid('no-such-uuid'))
        ->toBe(0);
});

test('countSavedSearchByUuid() returns one after insert', function (): void {
    $repo = searchTestRepo();
    $uuid = searchTestUuid();
    $repo->insertSavedSearch([
        'q' => 'travel',
    ], '2026-07-12 00:00:00', 1, $uuid, null);

    expect($repo->countSavedSearchByUuid($uuid))
        ->toBe(1);
});

test('insertSavedSearch() returns the new autoincrement id', function (): void {
    $repo = searchTestRepo();

    $id = $repo->insertSavedSearch([
        'q' => 'family',
    ], '2026-07-12 00:00:00', null, searchTestUuid(), null);

    expect($id)
        ->toBeGreaterThan(0);
    $row = $repo->findSavedSearchById($id);
    expect($row)
        ->not->toBeNull();
    if (! $row instanceof Search) {
        throw new RuntimeException('unreachable');
    }
    expect($row->createdBy)
        ->toBeNull()
        ->and($row->forkedFrom)
        ->toBeNull();
});

test('insertSavedSearch() stores forked_from', function (): void {
    $repo = searchTestRepo();
    $parentId = $repo->insertSavedSearch([
        'q' => 'parent',
    ], '2026-07-12 00:00:00', 1, searchTestUuid(), null);
    $childId = $repo->insertSavedSearch([
        'q' => 'child',
    ], '2026-07-12 00:00:00', 1, searchTestUuid(), $parentId);

    $row = $repo->findSavedSearchById($childId);

    expect($row)
        ->not->toBeNull();
    if (! $row instanceof Search) {
        throw new RuntimeException('unreachable');
    }
    expect($row->forkedFrom)
        ->toBe($parentId);
});

test('findSavedSearchRulesByIds() returns decoded rules keyed by id', function (): void {
    $repo = searchTestRepo();
    $firstId = $repo->insertSavedSearch([
        'q' => 'nature',
    ], '2026-07-12 00:00:00', 1, searchTestUuid(), null);
    $secondId = $repo->insertSavedSearch([
        'q' => 'travel',
        'fields' => [
            'allwords' => [
                'words' => ['travel'],
            ],
        ],
    ], '2026-07-12 00:00:00', 1, searchTestUuid(), null);

    $rules = $repo->findSavedSearchRulesByIds([$firstId, $secondId]);

    expect($rules[$firstId])->toBe([
        'q' => 'nature',
    ])
        ->and($rules[$secondId])->toBe([
            'q' => 'travel',
            'fields' => [
                'allwords' => [
                    'words' => ['travel'],
                ],
            ],
        ]);
});

test('findSavedSearchRulesByIds() returns empty array for an empty id list', function (): void {
    expect(searchTestRepo()->findSavedSearchRulesByIds([]))->toBe([]);
});

test('findSavedSearchRulesByIds() omits ids with no matching row', function (): void {
    expect(searchTestRepo()->findSavedSearchRulesByIds([999999]))->toBe([]);
});

test('findSavedSearchRulesByIds() decodes a null rules column to null', function (): void {
    // insertSavedSearch() always writes a real array via persist() (never
    // a literal SQL NULL) -- the only way to exercise the `rules`
    // column's real NULLable-JSON shape is a raw insert bypassing that
    // method.
    $conn = DbConnection::build();
    $conn->executeStatement(
        'INSERT INTO search (rules, created_on, created_by, search_uuid, forked_from) VALUES (NULL, ?, ?, ?, NULL)',
        ['2026-07-12 00:00:00', 1, searchTestUuid()]
    );
    $id = (int) $conn->lastInsertId();

    $rules = searchTestRepo()
        ->findSavedSearchRulesByIds([$id]);

    expect($rules[$id])->toBeNull();
});

test('getDbVersion() returns a non-empty version string', function (): void {
    expect(searchTestRepo()->getDbVersion())
        ->toMatch('/^\d+\.\d+/');
});

test('countImagesGroupedBy() returns counts ordered desc', function (): void {
    // Author names deliberately DISAGREE with count order alphabetically
    // ('Zzz Author' has the higher count but sorts last) -- the original
    // Integration spec's own 'Ansel Adams'/'Dorothea Lange' pair happens
    // to sort the same way alphabetically as by count DESC, so it can't
    // tell a real ORDER BY counter DESC apart from GROUP BY's own
    // incidental (frequently alphabetical) scan order.
    //
    // Exempt from tests/Pest.php's blanket per-test transaction:
    // `author` is part of a FULLTEXT index (images_ft_author), and
    // InnoDB's FULLTEXT auxiliary-index maintenance on an UPDATE that
    // changes an indexed column's value can deadlock against another
    // --parallel worker's own concurrent images write when held open for
    // a whole test's duration -- same mechanism, same fix, as
    // TagServiceTest.php's 'getTagIds() creates a new tag for a plain
    // name when allowed' (reproduced live there: DeadlockException).
    DbTransactionTestOverride::rollback();
    $conn = DbConnection::build();
    $conn->executeStatement("UPDATE images SET author = 'Zzz Author' WHERE id IN (1, 2)");
    $conn->executeStatement("UPDATE images SET author = 'Aaa Author' WHERE id = 3");

    try {
        $rows = searchTestRepo()
            ->countImagesGroupedBy('i.author', 'author', new SqlCondition('i.author IS NOT NULL'), true);

        expect($rows)
            ->toBe([
                [
                    'author' => 'Zzz Author',
                    'counter' => 2,
                ],
                [
                    'author' => 'Aaa Author',
                    'counter' => 1,
                ],
            ]);
    } finally {
        $conn->executeStatement('UPDATE images SET author = NULL WHERE id IN (1, 2, 3)');
    }
});

test('countImagesGroupedBy() returns empty for no match', function (): void {
    expect(searchTestRepo()->countImagesGroupedBy('i.author', 'author', new SqlCondition('i.author IS NOT NULL')))
        ->toBe([]);
});

test('findDistinctImageRows() returns the requested extra columns', function (): void {
    $rows = searchTestRepo()
        ->findDistinctImageRows(
            ['i.ratingScore AS rating_score'],
            new SqlCondition('i.id = :id', [
                'id' => 1,
            ]),
        );

    expect($rows)
        ->toBe([[
            'id' => 1,
            'rating_score' => 4.5,
        ]]);
});

test('findDistinctImageRows() returns empty for no match', function (): void {
    expect(searchTestRepo()->findDistinctImageRows(['i.ratingScore AS rating_score'], new SqlCondition('i.id = :id', [
        'id' => 99999,
    ])))->toBe([]);
});

test('findDistinctImageColumnValues() returns grouped, ordered values', function (): void {
    // Every fixture image shares height 150 -- collapses to one row via
    // this method's own GROUP BY.
    $values = searchTestRepo()
        ->findDistinctImageColumnValues('i.height', new SqlCondition(''));

    expect($values)
        ->toBe(['150']);
});

test('findCategoryIdsAndUppercats() returns matching rows', function (): void {
    $rows = searchTestRepo()
        ->findCategoryIdsAndUppercats(
            new SqlCondition('c.id IN (:ids)', [
                'ids' => [1, 2],
            ], [
                'ids' => ArrayParameterType::INTEGER,
            ]),
        );

    usort($rows, static fn (CategoryIdUppercats $a, CategoryIdUppercats $b): int => $a->id->value <=> $b->id->value);

    expect($rows)
        ->toEqual([
            new CategoryIdUppercats(CategoryId::from(1), '1'),
            new CategoryIdUppercats(CategoryId::from(2), '1,2'),
        ]);
});

test('findCategoryIdsAndUppercats() returns empty for no match', function (): void {
    expect(searchTestRepo()->findCategoryIdsAndUppercats(new SqlCondition('c.id = :id', [
        'id' => 99999,
    ])))->toBe([]);
});
