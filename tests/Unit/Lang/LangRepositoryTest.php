<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Lang\LangRepository;
use Piwigo\Lang\LanguageEntity;
use Piwigo\Lang\Projection\LanguageListing;

/**
 * Piwigo\Lang\LangRepository -- has its own dedicated
 * tests/Integration/LangRepositoryTest.php; this is the same spec ported
 * down to the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern.
 */
function langRepositoryTestRepo(): LangRepository
{
    $conn = DbConnection::build();
    $repo = EntityManagerFactory::build($conn)->getRepository(LanguageEntity::class);

    return $repo;
}

function langRepositoryTestInsert(Connection $conn, string $id, string $version, ?string $name): void
{
    $conn->createQueryBuilder()
        ->insert('languages')
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

function langRepositoryTestDelete(Connection $conn, string $id): void
{
    $conn->createQueryBuilder()
        ->delete('languages')
        ->where('id = :id')
        ->setParameter('id', $id)
        ->executeStatement();
}

test('findAllRows() returns every row as a LanguageListing, ordered by name', function (): void {
    $conn = DbConnection::build();
    $ids = ['zz_ZZ', 'aa_AA'];

    try {
        langRepositoryTestInsert($conn, 'zz_ZZ', '1.0', 'Zeta Language');
        langRepositoryTestInsert($conn, 'aa_AA', '1.0', 'Alpha Language');

        $all = langRepositoryTestRepo()
            ->findAllRows();
        $ours = array_values(array_filter($all, static fn (LanguageListing $l): bool => in_array($l->id, $ids, true)));

        expect($ours)
            ->toEqual([
                new LanguageListing('aa_AA', 'Alpha Language'),
                new LanguageListing('zz_ZZ', 'Zeta Language'),
            ]);
    } finally {
        foreach ($ids as $id) {
            langRepositoryTestDelete($conn, $id);
        }
    }
});

test('findAllRows() drops rows with a null name', function (): void {
    $conn = DbConnection::build();
    $id = 'yy_YY';

    try {
        langRepositoryTestInsert($conn, $id, '1.0', null);

        $all = langRepositoryTestRepo()
            ->findAllRows();
        $matching = array_filter($all, static fn (LanguageListing $l): bool => $l->id === $id);

        expect($matching)
            ->toBe([]);
    } finally {
        langRepositoryTestDelete($conn, $id);
    }
});
