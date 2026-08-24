<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionHandler;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;

/**
 * Piwigo\Session\SessionHandler -- a thin SessionHandlerInterface delegate
 * to SessionService, real PHP session-module entry points. No dedicated
 * Integration/Browser spec of its own.
 *
 * Exercises the real open/write/read/close/destroy/gc round trip
 * against a real, owned session row (uniqid()-based id, same pattern
 * as SessionUserResolverTest.php). write()'s own catch(Throwable)
 * branch is not attempted -- forcing a real DriverException would need
 * an actually-broken DB connection, too invasive for this class's own
 * thin-delegate role.
 */
test('open/write/read/close/destroy/gc all delegate to a real SessionService round trip', function (): void {
    $repo = TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), SessionRepository::class);
    $service = new SessionService($repo, new CurrentConfig());
    $pwgSession = new SessionHandler($service, new CurrentLogger());
    $sessionId = str_replace('.', '-', uniqid('pwg-session-test-', true));

    expect($pwgSession->open('', ''))
        ->toBeTrue()
        ->and($pwgSession->write($sessionId, 'pwg_uid|i:7;'))
        ->toBeTrue()
        ->and($pwgSession->read($sessionId))
        ->toBe('pwg_uid|i:7;')
        ->and($pwgSession->close())
        ->toBeTrue();

    $gcResult = $pwgSession->gc(1440);
    expect($gcResult)
        ->toBeGreaterThanOrEqual(0);

    expect($pwgSession->destroy($sessionId))
        ->toBeTrue()
        ->and($pwgSession->read($sessionId))
        ->toBe('');
});
