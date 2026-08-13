<?php

declare(strict_types=1);

use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

/**
 * Piwigo\Auth\PasswordService -- has its own dedicated
 * tests/Integration/PasswordServiceTest.php; this ports its scenarios
 * down to the Unit suite via the real-DB-no-HTTP pattern (only
 * verify()'s rehash path touches the DB at all, through
 * PasswordRepository). No Kernel::boot() needed -- neither
 * PasswordService nor PasswordRepository has a Kernel/container
 * dependency; Env::testModeIsActive() reads $_SERVER directly.
 *
 * Fixture user 3 (regular_user) is used for the rehash test, not user 4
 * -- tests/Unit/Auth/PasswordRepositoryTest.php's own tests already
 * mutate user 4's password column, and these are 2 separate
 * --parallel-eligible Unit files, same "own your row space" reasoning
 * as every other repo/service test this session.
 *
 * tests/bootstrap.php sets $_SERVER['HTTP_X_PIWIGO_ENV'] = 'test'
 * globally for the whole Unit-suite process, so Env::testModeIsActive()
 * is already true by default here -- unlike the Integration original
 * (which explicitly sets the header to prove the test-mode cost=4
 * path), the more meaningful port is the *other* branch: cost=13 when
 * the header is temporarily absent. Every other hash() call in this
 * file implicitly already exercises cost=4 via that global default.
 */
function passwordServiceTestService(): PasswordService
{
    return new PasswordService(new PasswordRepository(EntityManagerFactory::build(DbConnection::build())), new DeploymentPolicy());
}

const PASSWORD_SERVICE_TEST_USER_ID = 3;
const PASSWORD_SERVICE_TEST_ORIGINAL_HASH = '$2y$04$5iHho2h8WHWpsthi7sIHbOx0Sl9Tv.a7i2UQpqOH.KmYXISugJ8WC';

test('hash() produces a bcrypt hash', function (): void {
    $hash = passwordServiceTestService()
        ->hash('correcthorsebatterystaple');

    expect($hash)
        ->toStartWith('$2y$');
});

test('verify() accepts its own hash and rejects a wrong password', function (): void {
    $service = passwordServiceTestService();
    $hash = $service->hash('hunter2');

    expect($service->verify('hunter2', $hash))
        ->toBeTrue()
        ->and($service->verify('wrong', $hash))
        ->toBeFalse();
});

test('hash() uses cost 13, not test-mode\'s cost 4, when the test-mode header is absent', function (): void {
    $original = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
    unset($_SERVER['HTTP_X_PIWIGO_ENV']);

    try {
        $hash = passwordServiceTestService()
            ->hash('costcheck');

        // bcrypt hash format: $2y$<cost>$<22-char-salt><31-char-hash>
        expect($hash)
            ->toStartWith('$2y$13$');
    } finally {
        if ($original === null) {
            unset($_SERVER['HTTP_X_PIWIGO_ENV']);
        } else {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $original;
        }
    }
});

test('verify() accepts a legacy phpass hash and rejects a wrong password', function (): void {
    // Precomputed with a fixed salt via the (now-removed) vendored
    // phpass library, cross-checked byte-for-byte against
    // verifyLegacyPhpass()'s extraction -- same fixture value as the
    // Integration original.
    $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';
    $service = passwordServiceTestService();

    expect($service->verify('legacyPhpassPassw0rd!', $phpassHash))
        ->toBeTrue()
        ->and($service->verify('wrongpassword', $phpassHash))
        ->toBeFalse();
});

test('verify() accepts a legacy phpass hash without touching the DB when no user id is given', function (): void {
    // No $userId passed: verify()'s `$userId === null` branch returns
    // true immediately, before reaching the rehash write.
    $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';

    expect(passwordServiceTestService()->verify('legacyPhpassPassw0rd!', $phpassHash))
        ->toBeTrue();
});

test('verify() rehashes a legacy phpass hash to bcrypt when a user id is given', function (): void {
    $phpassHash = '$P$5testsalt/.6ES3kLR5L.kwZkBtHpD/';
    $conn = DbConnection::build();
    $service = passwordServiceTestService();

    try {
        expect($service->verify('legacyPhpassPassw0rd!', $phpassHash, PASSWORD_SERVICE_TEST_USER_ID))
            ->toBeTrue();

        $newHash = $conn->createQueryBuilder()
            ->select('password')
            ->from('users')
            ->where('id = :id')
            ->setParameter('id', PASSWORD_SERVICE_TEST_USER_ID)
            ->executeQuery()
            ->fetchOne();

        expect($newHash)
            ->toBeString()
            ->toStartWith('$2y$')
            ->and($service->verify('legacyPhpassPassw0rd!', is_string($newHash) ? $newHash : ''))
            ->toBeTrue();
    } finally {
        $conn->executeStatement(
            'UPDATE users SET password = ? WHERE id = ?',
            [PASSWORD_SERVICE_TEST_ORIGINAL_HASH, PASSWORD_SERVICE_TEST_USER_ID]
        );
    }
});

test('verifyLegacyPhpass() rejects malformed hashes', function (): void {
    $service = passwordServiceTestService();

    expect($service->verifyLegacyPhpass('anything', 'not-a-phpass-hash'))
        ->toBeFalse()
        ->and($service->verifyLegacyPhpass('anything', '$2y$04$tooshortforbcryptbutwrongprefix'))
        ->toBeFalse();
});

test('verifyLegacyPhpass() rejects a correctly-sized hash with the wrong prefix', function (): void {
    // Exactly 34 chars (passes the length guard), but the first 3 chars
    // are neither '$P$' nor '$H$' -- a distinct rejection branch from
    // the "wrong length" case above (real phpass hashes always use one
    // of those 2 prefixes).
    $wrongPrefixHash = '$Q$5testsalt/.6ES3kLR5L.kwZkBtHpD/';
    expect(strlen($wrongPrefixHash))
        ->toBe(34);

    expect(passwordServiceTestService()->verifyLegacyPhpass('legacyPhpassPassw0rd!', $wrongPrefixHash))
        ->toBeFalse();
});

test('verifyLegacyPhpass() rejects a hash with an out-of-range cost factor', function (): void {
    // Correct '$P$' prefix and 34-char length, but hash[3] (the cost
    // log2 character) is '.' -- itoa64 index 0, below the 7-30 valid
    // range real phpass costs use.
    $outOfRangeCostHash = '$P$.testsalt/.6ES3kLR5L.kwZkBtHpD/';
    expect(strlen($outOfRangeCostHash))
        ->toBe(34);

    expect(passwordServiceTestService()->verifyLegacyPhpass('legacyPhpassPassw0rd!', $outOfRangeCostHash))
        ->toBeFalse();
});
