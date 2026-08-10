<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\ImageEntity;
use Piwigo\Rate\Projection\RaterInfo;
use Piwigo\Rate\Projection\RateSummary;
use Piwigo\Rate\Projection\RateSummaryForElement;
use Piwigo\Rate\Projection\RatingReportRow;
use Piwigo\Rate\RateEntity;
use Piwigo\Rate\RateRepository;

/**
 * Piwigo\Rate\RateRepository -- has its own dedicated
 * tests/Integration/RateRepositoryTest.php; this ports its 33 tests down
 * to the Unit suite via the real-DB-no-HTTP ImageRepositoryTest.php
 * pattern. Every mutating test below either operates on a disposable
 * rate row (never colliding with a real fixture (element_id, user_id)
 * pair) or restores the exact fixture value it touched before returning,
 * matching the Integration original's own discipline exactly -- this
 * suite's DB is shared across the whole run, not reloaded per class.
 * updateRatingScores()/clearRatingScores() write rating_score directly
 * rather than deriving it from a rate row -- their own tests use a
 * disposable image (rateTestInsertImage()) instead, not a real fixture
 * image's score: SectionRepositoryTest.php's own findTopRatedImageIds()
 * tests read every image's rating_score unfiltered, and briefly mutating
 * a real one (even restored after) raced that file under --parallel --
 * confirmed live.
 *
 * fixture: user1 rated element1(=5) and element3(=5); user3 rated
 * element1(=4) and element4(=2); user4 rated element2(=3). images
 * rating_score: 1=>4.50, 2=>3.00, 3=>5.00, 4=>2.00, 5=>NULL.
 *
 * Confirmed-equivalent mutations, not individually tested: every
 * `is_numeric(...) ? (int) ... : default`/`(...) instanceof Vo ? ->value
 * : default` cast across this file's own methods
 * (findElementIdsForUserAndAnonymousId(), findImageIdsWithStaleRatingScore(),
 * findUsernamesById(), findRatingReport(), findUsersWithStatusByIdUsername(),
 * findAverageRatePerElement(), findTopRatedImageIds(), findUserRate()) is
 * unreachable on this driver -- getSingleColumnResult() on a plain
 * column already returns a real native PHP int, and getArrayResult() on
 * a VO-typed column already returns the real VO instance, same root
 * cause documented throughout this project's other Unit-suite files;
 * `findImageIdsWithStaleRatingScore()`'s own per-row `! is_array($row)`
 * guards (findUsersWithStatusByIdUsername(), findAverageRatePerElement())
 * are dead code under any real query result; findImageThumbInfoByIds()'s
 * own `if ($imageIds === []) { return []; }` early return is
 * unobservable if skipped -- confirmed live (sed-mutate-and-rerun:
 * disabling it still returns `[]`, DBAL's own `ArrayParameterType`
 * expansion of an empty array already matches nothing on this driver,
 * same root cause as PermalinkRepositoryTest.php's own
 * findPermalinkMatches() finding); findUserRate()'s own `setMaxResults(1)`
 * is unobservable at any other value -- only `$values[0]` is ever read
 * regardless of how many rows come back, same reasoning as
 * PermalinkRepositoryTest.php's own findOldCategoryId() finding.
 */
function rateTestRepo(): RateRepository
{
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(RateEntity::class);

    return $repo;
}

/**
 * getEntityManager() is protected on Doctrine's own EntityRepository base
 * class -- the em->clear() staleness tests below need direct
 * EntityManager access (for find()) alongside the repo, same
 * CaddieRepositoryTest.php precedent.
 *
 * @return array{0: RateRepository, 1: Doctrine\ORM\EntityManagerInterface}
 */
function rateTestRepoWithEm(): array
{
    $em = EntityManagerFactory::build(DbConnection::build());
    $repo = $em->getRepository(RateEntity::class);

    return [$repo, $em];
}

function rateTestFetchCount(Connection $conn, int $elementId, int $userId): int
{
    $value = $conn->createQueryBuilder()
        ->select('COUNT(*)')
        ->from('rate')
        ->where('element_id = :elementId')
        ->andWhere('user_id = :userId')
        ->setParameter('elementId', $elementId)
        ->setParameter('userId', $userId)
        ->executeQuery()
        ->fetchOne();

    return is_numeric($value) ? (int) $value : 0;
}

/**
 * Doctrine's mysqli driver returns a FLOAT column (rating_score) as a
 * native PHP float, not a string -- is_numeric()/(float) cast, not
 * is_string(), is the correct narrowing here.
 */
function rateTestFetchRatingScore(Connection $conn, int $imageId): ?float
{
    $value = $conn->createQueryBuilder()
        ->select('rating_score')
        ->from('images')
        ->where('id = :id')
        ->setParameter('id', $imageId)
        ->executeQuery()
        ->fetchOne();

    return is_numeric($value) ? (float) $value : null;
}

/**
 * A disposable image, not one of the fixture's own shared 1-5 --
 * updateRatingScores()/clearRatingScores() write rating_score directly,
 * and SectionRepositoryTest.php's own findTopRatedImageIds() tests read
 * the fixture's rating_score values across ALL images unfiltered, so
 * mutating a real fixture image's score (even briefly, restored in
 * finally) raced that file under --parallel -- confirmed live.
 */
function rateTestInsertImage(Connection $conn): int
{
    $conn->createQueryBuilder()->insert('images')
        ->values(['file' => ':file', 'path' => ':path'])
        ->setParameter('file', 'rate-test.jpg')
        ->setParameter('path', 'upload/rate-test.jpg')
        ->executeStatement();

    return (int) $conn->lastInsertId();
}

test('findElementIdsForUserAndAnonymousId() matches the fixture', function (): void {
    // fixture: user_id 1 rated element 1 and element 3, both with
    // anonymous_id ''
    $ids = rateTestRepo()->findElementIdsForUserAndAnonymousId(UserId::from(1), '');
    sort($ids);

    expect($ids)->toBe([1, 3]);
});

test('findElementIdsForUserAndAnonymousId() returns empty for no match', function (): void {
    expect(rateTestRepo()->findElementIdsForUserAndAnonymousId(UserId::from(1), '10.0.0'))->toBe([]);
});

test('deleteByUserAnonymousAndElements() removes matching rows', function (): void {
    $repo = rateTestRepo();
    $repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-a', 3);

    $repo->deleteByUserAnonymousAndElements(UserId::from(2), 'disp-a', [5]);

    expect($repo->findElementIdsForUserAndAnonymousId(UserId::from(2), 'disp-a'))->toBe([]);
});

test('deleteByUserAnonymousAndElements() is a no-op for empty ids', function (): void {
    $repo = rateTestRepo();
    $repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-b', 3);

    try {
        $repo->deleteByUserAnonymousAndElements(UserId::from(2), 'disp-b', []);

        expect($repo->findElementIdsForUserAndAnonymousId(UserId::from(2), 'disp-b'))->toBe([5]);
    } finally {
        $repo->deleteByUserAnonymousAndElements(UserId::from(2), 'disp-b', [5]);
    }
});

test('reassignAnonymousId() moves rates from the old anonymous_id to the new one', function (): void {
    $repo = rateTestRepo();
    $repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-c-old', 3);

    try {
        $repo->reassignAnonymousId(UserId::from(2), 'disp-c-old', 'disp-c-new');

        expect($repo->findElementIdsForUserAndAnonymousId(UserId::from(2), 'disp-c-old'))->toBe([])
            ->and($repo->findElementIdsForUserAndAnonymousId(UserId::from(2), 'disp-c-new'))->toBe([5]);
    } finally {
        $repo->deleteByUserAnonymousAndElements(UserId::from(2), 'disp-c-new', [5]);
    }
});

test('deleteExistingRate() scoped to anonymous_id spares a mismatched one', function (): void {
    $repo = rateTestRepo();
    $conn = DbConnection::build();
    $repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-d', 1);

    try {
        // Mismatched anonymous_id -- must not delete.
        $repo->deleteExistingRate(ImageId::from(5), UserId::from(2), 'wrong-ip');

        expect(rateTestFetchCount($conn, 5, 2))->toBe(1);
    } finally {
        $repo->deleteExistingRate(ImageId::from(5), UserId::from(2), null);
    }
});

test('deleteExistingRate() with a null anonymous_id matches any', function (): void {
    $repo = rateTestRepo();
    $conn = DbConnection::build();
    $repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-e', 1);

    $repo->deleteExistingRate(ImageId::from(5), UserId::from(2), null);

    expect(rateTestFetchCount($conn, 5, 2))->toBe(0);
});

test('insertRate() persists the given rate', function (): void {
    $repo = rateTestRepo();
    $conn = DbConnection::build();
    $repo->insertRate(ImageId::from(5), UserId::from(2), 'disp-f', 3);

    try {
        $value = $conn->createQueryBuilder()
            ->select('rate')
            ->from('rate')
            ->where('element_id = 5')
            ->andWhere('user_id = 2')
            ->executeQuery()
            ->fetchOne();

        expect($value)->toBe(3);
    } finally {
        $repo->deleteExistingRate(ImageId::from(5), UserId::from(2), null);
    }
});

test('findRateSummaries() matches the fixture', function (): void {
    // findRateSummaries()'s own `! is_numeric($row['element_id'])`
    // defensive `continue` (and findUsersWithStatusByIdUsername()'s
    // identically-shaped one below) is unreachable through any real
    // row: `rate.element_id`/`users.id` are both NOT NULL integer PKs
    // enforced by the schema.
    $summaries = rateTestRepo()->findRateSummaries();

    // element 1: rates 5 (user 1) + 4 (user 3)
    expect($summaries[1])->toEqual(new RateSummary(2, 9.0))
        // element 2: rate 3 (user 4)
        ->and($summaries[2])->toEqual(new RateSummary(1, 3.0))
        // element 5 has no rate at all
        ->and($summaries)->not->toHaveKey(5);
});

test('updateRatingScores() persists the given score', function (): void {
    $conn = DbConnection::build();
    $imageId = rateTestInsertImage($conn);

    try {
        rateTestRepo()->updateRatingScores([
            ['id' => $imageId, 'ratingScore' => 4.75],
        ]);

        expect(rateTestFetchRatingScore($conn, $imageId))->toBe(4.75);
    } finally {
        $conn->createQueryBuilder()->delete('images')->where('id = :id')->setParameter('id', $imageId)->executeStatement();
    }
});

test('updateRatingScores() clears the identity map, so a later find() sees the real update instead of a stale cached entity', function (): void {
    [$repo, $em] = rateTestRepoWithEm();
    $imageId = rateTestInsertImage(DbConnection::build());
    $imageIdVo = ImageId::from($imageId);

    try {
        $tracked = $em->find(ImageEntity::class, $imageIdVo);
        expect($tracked)->not->toBeNull();

        $repo->updateRatingScores([
            ['id' => $imageId, 'ratingScore' => 4.75],
        ]);

        $refetched = $em->find(ImageEntity::class, $imageIdVo);
        expect($refetched)->not->toBeNull();
        if (! $refetched instanceof ImageEntity) {
            throw new RuntimeException('unreachable');
        }
        expect($refetched->ratingScore)->toBe(4.75);
    } finally {
        DbConnection::build()->createQueryBuilder()->delete('images')->where('id = :id')->setParameter('id', $imageId)->executeStatement();
    }
});

test('updateRatingScores() is a no-op for an empty list', function (): void {
    $conn = DbConnection::build();
    $original = rateTestFetchRatingScore($conn, 1);

    rateTestRepo()->updateRatingScores([]);

    expect(rateTestFetchRatingScore($conn, 1))->toBe($original);
});

test('findImageIdsWithStaleRatingScore() finds an image with a leftover score but no rate row', function (): void {
    // element 4 (rating_score 2.00) has a rate row in the fixture, so
    // it's not "stale"; simulate its rate being deleted without the
    // score being recomputed yet.
    $conn = DbConnection::build();
    $deletedRow = $conn->createQueryBuilder()
        ->select('*')
        ->from('rate')
        ->where('element_id = 4')
        ->executeQuery()
        ->fetchAssociative();
    expect($deletedRow)->toBeArray();
    if (! is_array($deletedRow)) {
        throw new RuntimeException('unreachable');
    }

    $conn->createQueryBuilder()->delete('rate')->where('element_id = 4')->executeStatement();

    try {
        expect(rateTestRepo()->findImageIdsWithStaleRatingScore())->toBe([4]);
    } finally {
        $conn->createQueryBuilder()
            ->insert('rate')
            ->values([
                'user_id' => ':userId',
                'element_id' => ':elementId',
                'anonymous_id' => ':anonymousId',
                'rate' => ':rate',
                'date' => ':date',
            ])
            ->setParameter('userId', $deletedRow['user_id'])
            ->setParameter('elementId', $deletedRow['element_id'])
            ->setParameter('anonymousId', $deletedRow['anonymous_id'])
            ->setParameter('rate', $deletedRow['rate'])
            ->setParameter('date', $deletedRow['date'])
            ->executeStatement();
    }
});

test('clearRatingScores() nulls only the given ids', function (): void {
    $conn = DbConnection::build();
    $imageId1 = rateTestInsertImage($conn);
    $imageId2 = rateTestInsertImage($conn);
    $conn->createQueryBuilder()->update('images')->set('rating_score', ':score')->where('id = :id')->setParameter('score', 4.5)->setParameter('id', $imageId1)->executeStatement();
    $conn->createQueryBuilder()->update('images')->set('rating_score', ':score')->where('id = :id')->setParameter('score', 3.0)->setParameter('id', $imageId2)->executeStatement();

    try {
        rateTestRepo()->clearRatingScores([$imageId1, $imageId2]);

        expect(rateTestFetchRatingScore($conn, $imageId1))->toBeNull()
            ->and(rateTestFetchRatingScore($conn, $imageId2))->toBeNull()
            // untouched
            ->and(rateTestFetchRatingScore($conn, 3))->toBe(5.0);
    } finally {
        $conn->createQueryBuilder()->delete('images')->where('id = :id')->setParameter('id', $imageId1)->executeStatement();
        $conn->createQueryBuilder()->delete('images')->where('id = :id')->setParameter('id', $imageId2)->executeStatement();
    }
});

test('clearRatingScores() clears the identity map, so a later find() sees the real deletion instead of a stale cached entity', function (): void {
    [$repo, $em] = rateTestRepoWithEm();
    $conn = DbConnection::build();
    $imageId = rateTestInsertImage($conn);
    $conn->createQueryBuilder()->update('images')->set('rating_score', ':score')->where('id = :id')->setParameter('score', 4.5)->setParameter('id', $imageId)->executeStatement();
    $imageIdVo = ImageId::from($imageId);

    try {
        $tracked = $em->find(ImageEntity::class, $imageIdVo);
        expect($tracked)->not->toBeNull();
        if (! $tracked instanceof ImageEntity) {
            throw new RuntimeException('unreachable');
        }
        expect($tracked->ratingScore)->not->toBeNull();

        $repo->clearRatingScores([$imageId]);

        $refetched = $em->find(ImageEntity::class, $imageIdVo);
        expect($refetched)->not->toBeNull();
        if (! $refetched instanceof ImageEntity) {
            throw new RuntimeException('unreachable');
        }
        expect($refetched->ratingScore)->toBeNull();
    } finally {
        $conn->createQueryBuilder()->delete('images')->where('id = :id')->setParameter('id', $imageId)->executeStatement();
    }
});

test('clearRatingScores() is a no-op for empty ids', function (): void {
    $conn = DbConnection::build();
    $original = rateTestFetchRatingScore($conn, 1);

    rateTestRepo()->clearRatingScores([]);

    expect(rateTestFetchRatingScore($conn, 1))->toBe($original);
});

test('findUsernamesById() maps id to username', function (): void {
    $usernames = rateTestRepo()->findUsernamesById();
    ksort($usernames);

    expect($usernames)->toBe([1 => 'fixture_admin', 2 => 'guest', 3 => 'regular_user', 4 => 'power_user']);
});

test('countRatedElements() with no filters', function (): void {
    expect(rateTestRepo()->countRatedElements(null, false, []))->toBe(4);
});

test('countRatedElements() filtered by category', function (): void {
    $repo = rateTestRepo();

    // category 1 -> images [1,2,3], all three rated at least once
    expect($repo->countRatedElements(null, false, [1]))->toBe(3)
        // category 2 -> images [4,5]; only 4 is rated
        ->and($repo->countRatedElements(null, false, [2]))->toBe(1);
});

test('countRatedElements() filtered by user', function (): void {
    $repo = rateTestRepo();

    // user 1 rated elements 1 and 3
    expect($repo->countRatedElements(UserId::from(1), false, []))->toBe(2)
        // everyone except user 1 rated elements 1, 2, 4
        ->and($repo->countRatedElements(UserId::from(1), true, []))->toBe(3);
});

test('findRatingReport() matches the fixture', function (): void {
    $rows = rateTestRepo()->findRatingReport(null, false, [], 'score', 10, 0);

    expect($rows)->toHaveCount(4);
    $byId = [];
    foreach ($rows as $row) {
        $byId[$row->id] = $row;
    }

    expect($byId[1]->score)->toBe(4.5)
        ->and($byId[1]->avgRates)->toBe(4.5)
        ->and($byId[1]->nbRates)->toBe(2)
        ->and($byId[1]->sumRates)->toBe(9.0)
        ->and($byId[3]->nbRates)->toBe(1)
        ->and($byId[3]->sumRates)->toBe(5.0);
});

test('findRatingReport() filters by category', function (): void {
    $rows = rateTestRepo()->findRatingReport(null, false, [2], 'i.id ASC', 10, 0);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->id)->toBe(4);
});

test('findRatingReport() orders and paginates', function (): void {
    $rows = rateTestRepo()->findRatingReport(null, false, [], 'sum_rates', 2, 0);

    expect($rows)->toHaveCount(2)
        ->and(array_map(static fn (RatingReportRow $r): int => $r->id, $rows))->toBe([1, 3]);
});

test('findRateRowsForElement() returns every rate for that element', function (): void {
    $rows = rateTestRepo()->findRateRowsForElement(ImageId::from(1));

    expect($rows)->toHaveCount(2)
        ->and(array_sum(array_column($rows, 'rate')))->toBe(9)
        ->and(array_map(static fn (ImageId $id): int => $id->value, array_column($rows, 'elementId')))->toBe([1, 1]);
});

test('findRateRowsForElement() returns empty for an unrated element', function (): void {
    expect(rateTestRepo()->findRateRowsForElement(ImageId::from(5)))->toBe([]);
});

test('countAllRates() matches the fixture', function (): void {
    expect(rateTestRepo()->countAllRates())->toBe(5);
});

test('findUsersWithStatusByIdUsername() returns every rater with their status', function (): void {
    $users = rateTestRepo()->findUsersWithStatusByIdUsername();

    expect($users)->toHaveCount(4);
    $byId = [];
    foreach ($users as $user) {
        $byId[$user->id] = $user;
    }

    expect($byId[1])->toEqual(new RaterInfo(1, 'fixture_admin', 'webmaster'))
        ->and($byId[2])->toEqual(new RaterInfo(2, 'guest', 'guest'));
});

test('findAllRatesOrderedByDateDesc() matches the fixture', function (): void {
    $rows = rateTestRepo()->findAllRatesOrderedByDateDesc();

    expect($rows)->toHaveCount(5)
        ->and(array_sum(array_column($rows, 'rate')))->toBe(19);
    $rowUserIds = array_map(static fn (UserId $id): int => $id->value, array_column($rows, 'userId'));
    sort($rowUserIds);
    expect($rowUserIds)->toBe([1, 1, 3, 3, 4]);
});

test('findImageThumbInfoByIds() returns the requested images', function (): void {
    $rows = rateTestRepo()->findImageThumbInfoByIds([1, 4]);

    expect($rows)->toHaveCount(2);
    $byId = [];
    foreach ($rows as $row) {
        $byId[$row->id] = $row;
    }

    // Every field, not just name/file -- id (ImageId-typed) and every
    // plain-string column alike. The upload path's own hash suffix is
    // baked into each fixture file at regen time and genuinely differs
    // between piwigo-17.0.sql and piwigo-17.0-pgsql.sql (both generated
    // via separate, independent install+upload runs), same driver split
    // documented in NotificationRepositoryTest.php/WsTopLevelTest.php.
    $expectedPath = getenv('PIWIGO_DB_DRIVER') === 'pgsql'
        ? 'upload/2026/08/01/20260801000000-2e7e2ce3.jpg'
        : 'upload/2026/08/01/20260801000000-2e7e6c90.jpg';
    expect($byId[1]->id)->toBe(1)
        ->and($byId[1]->name)->toBe('Photo 1')
        ->and($byId[1]->file)->toBe('fixture-photo-1.jpg')
        ->and($byId[1]->path)->toBe($expectedPath)
        ->and($byId[1]->representativeExt)->toBeNull()
        ->and($byId[1]->level)->toBe(0)
        ->and($byId[4]->id)->toBe(4)
        ->and($byId[4]->file)->toBe('fixture-photo-4.jpg');
});

test('findImageThumbInfoByIds() is a no-op for empty ids', function (): void {
    expect(rateTestRepo()->findImageThumbInfoByIds([]))->toBe([]);
});

test('findAverageRatePerElement() matches the fixture', function (): void {
    $averages = rateTestRepo()->findAverageRatePerElement();

    expect($averages[1])->toBe(4.5)
        ->and($averages[2])->toBe(3.0)
        ->and($averages[3])->toBe(5.0)
        ->and($averages[4])->toBe(2.0)
        ->and($averages)->not->toHaveKey(5);
});

test('findTopRatedImageIds() orders by rating_score desc', function (): void {
    // rating_score: 3=5.00, 1=4.50, 2=3.00, 4=2.00, 5=NULL (sorts last)
    $repo = rateTestRepo();

    expect($repo->findTopRatedImageIds(3))->toBe([3, 1, 2])
        ->and($repo->findTopRatedImageIds(10))->toBe([3, 1, 2, 4, 5]);
});

test('findRateSummaryForElement() matches the fixture', function (): void {
    // element 1 has 2 rates (5 from user 1, 4 from user 3) -> count=2,
    // average=ROUND((5+4)/2, 2)=4.5.
    expect(rateTestRepo()->findRateSummaryForElement(ImageId::from(1)))->toEqual(new RateSummaryForElement(2, 4.5));
});

test('findRateSummaryForElement() is zero for an unrated element', function (): void {
    // element 5 has zero rate rows -- COUNT(rate)/AVG(rate) without a
    // GROUP BY still returns exactly one row (count=0, average=NULL),
    // never a false fetchAssociative() result.
    expect(rateTestRepo()->findRateSummaryForElement(ImageId::from(5)))->toEqual(new RateSummaryForElement(0, null));
});

test('findUserRate() returns the user\'s own rate', function (): void {
    expect(rateTestRepo()->findUserRate(ImageId::from(1), UserId::from(1), null))->toBe(5);
});

test('findUserRate() matches a non-null anonymous_id', function (): void {
    expect(rateTestRepo()->findUserRate(ImageId::from(1), UserId::from(1), ''))->toBe(5);
});

test('findUserRate() returns null when the anonymous_id does not match', function (): void {
    expect(rateTestRepo()->findUserRate(ImageId::from(1), UserId::from(1), 'no-such-anonymous-id'))->toBeNull();
});

test('findUserRate() returns null for a user with no rate on that element', function (): void {
    expect(rateTestRepo()->findUserRate(ImageId::from(1), UserId::from(999999), null))->toBeNull();
});
