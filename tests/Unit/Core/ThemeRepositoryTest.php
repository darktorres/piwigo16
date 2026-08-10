<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Core\Projection\ThemeListing;
use Piwigo\Core\ThemeEntity;
use Piwigo\Core\ThemeRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

/**
 * Piwigo\Core\ThemeRepository -- has no dedicated Integration test file
 * of its own (spec ported down from tests/Integration/ThemeCatalogTest.php,
 * which exercises findAllIdsAndNames() only indirectly through
 * ThemeCatalog::getPwgThemes()'s own real flow). Real DB, no HTTP --
 * same ImageRepositoryTest.php precedent as every other Unit-suite
 * Repository test. `themes` is empty in the fixture DB, so every row
 * here is a throwaway insert cleaned up in each test's own finally
 * block.
 *
 * findAllIdsAndNames()'s own `$id instanceof ThemeId ? $id->value :
 * null` narrowing is a confirmed-equivalent branch for every real row:
 * `id` is a NOT NULL PK hydrated through the `theme_id` custom Doctrine
 * Type on every getArrayResult() call, so the false (non-ThemeId)
 * branch is unreachable through normal query hydration -- no test
 * fabricates a non-ThemeId row to force it.
 */
function themeRepositoryTestRepo(): ThemeRepository
{
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(ThemeEntity::class);

    return $repo;
}

function themeRepositoryTestInsert(Connection $conn, string $id, string $version, ?string $name): void
{
    $conn->createQueryBuilder()
        ->insert('themes')
        ->values([
            'id' => ':id',
            'version' => ':version',
            'name' => ':name',
        ])
        ->setParameter('id', $id)
        ->setParameter('version', $version)
        ->setParameter('name', $name)
        ->executeStatement();
}

function themeRepositoryTestDelete(Connection $conn, string $id): void
{
    $conn->createQueryBuilder()
        ->delete('themes')
        ->where('id = :id')
        ->setParameter('id', $id)
        ->executeStatement();
}

test('findAllIdsAndNames() returns every row as a ThemeListing, ordered by name', function (): void {
    $conn = DbConnection::build();
    $ids = ['ut_theme_zebra', 'ut_theme_alpha'];

    try {
        themeRepositoryTestInsert($conn, 'ut_theme_zebra', '1.0', 'Zebra Theme');
        themeRepositoryTestInsert($conn, 'ut_theme_alpha', '1.0', 'Alpha Theme');

        $all = themeRepositoryTestRepo()->findAllIdsAndNames();
        $ours = array_values(array_filter($all, static fn (ThemeListing $t): bool => in_array($t->id, $ids, true)));

        expect($ours)->toEqual([
            new ThemeListing('ut_theme_alpha', 'Alpha Theme'),
            new ThemeListing('ut_theme_zebra', 'Zebra Theme'),
        ]);
    } finally {
        foreach ($ids as $id) {
            themeRepositoryTestDelete($conn, $id);
        }
    }
});

test('findAllIdsAndNames() drops rows with a null name but keeps scanning past them (continue, not break)', function (): void {
    $conn = DbConnection::build();
    $noNameId = 'ut_theme_noname';
    // Names sort NULL-first in ASC order on this driver, so the no-name
    // row is iterated before this one -- if the loop used `break`
    // instead of `continue` on the skip branch, this row would never be
    // reached at all.
    $afterId = 'ut_theme_after';

    try {
        themeRepositoryTestInsert($conn, $noNameId, '1.0', null);
        themeRepositoryTestInsert($conn, $afterId, '1.0', 'After No-Name Theme');

        $all = themeRepositoryTestRepo()->findAllIdsAndNames();
        $ids = array_map(static fn (ThemeListing $t): string => $t->id, $all);

        expect($ids)->not->toContain($noNameId)
            ->and($ids)->toContain($afterId);
    } finally {
        themeRepositoryTestDelete($conn, $noNameId);
        themeRepositoryTestDelete($conn, $afterId);
    }
});
