<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionUserResolver;

/**
 * Piwigo\Session\SessionUserResolver -- [SEC-33] resolves a raw session
 * cookie value to its logged-in user id. No dedicated Integration/
 * Browser spec of its own.
 *
 * `useIpAddressInKey: false` makes `SessionService::remoteAddrHash()`
 * return `''` deterministically (its own first guard), so the composite
 * DB key is just the cookie value itself -- avoids any dependency on a
 * real `$_SERVER['REMOTE_ADDR']`. A `uniqid()`-based cookie value per
 * test (not a literal) avoids colliding with a concurrent worktree's
 * identical test, same reasoning as `CsrfServiceTest.php`'s own
 * `csrfTestSessionId()` helper.
 */
function sessionUserResolverTestRepo(): SessionRepository
{
    return EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class);
}

test('resolveLoggedUserId returns null for a cookie value with no matching session row', function (): void {
    $repo = sessionUserResolverTestRepo();
    $resolver = new SessionUserResolver($repo);
    $cookieValue = str_replace('.', '-', uniqid('sur-test-missing-', true));

    $userId = $resolver->resolveLoggedUserId($cookieValue, useIpAddressInKey: false);

    expect($userId)
        ->toBeNull();
});

test('resolveLoggedUserId extracts the real pwg_uid from a real logged-in session row', function (): void {
    $repo = sessionUserResolverTestRepo();
    $resolver = new SessionUserResolver($repo);
    $cookieValue = str_replace('.', '-', uniqid('sur-test-loggedin-', true));

    $repo->write($cookieValue, 'pwg_uid|i:42;expire|i:9999999999;');

    try {
        $userId = $resolver->resolveLoggedUserId($cookieValue, useIpAddressInKey: false);

        expect($userId)
            ->toBe(42);
    } finally {
        $repo->destroy($cookieValue);
    }
});

test('resolveLoggedUserId returns null for a real anonymous session row with no pwg_uid key', function (): void {
    $repo = sessionUserResolverTestRepo();
    $resolver = new SessionUserResolver($repo);
    $cookieValue = str_replace('.', '-', uniqid('sur-test-anon-', true));

    $repo->write($cookieValue, 'expire|i:9999999999;');

    try {
        $userId = $resolver->resolveLoggedUserId($cookieValue, useIpAddressInKey: false);

        expect($userId)
            ->toBeNull();
    } finally {
        $repo->destroy($cookieValue);
    }
});
