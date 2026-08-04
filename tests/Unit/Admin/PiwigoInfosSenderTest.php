<?php

declare(strict_types=1);

use Piwigo\Admin\PiwigoInfosSender;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Logger;
use Piwigo\Image\ImageStdParams;

// send()'s real body talks to piwigo.org's PEM/telemetry endpoints over the
// network (via the static, non-injectable Piwigo\Http\HttpClientService::
// fetch()) and reads/writes a real DB config row partway through (the
// send_piwigo_infos_last_notice reload, before any of the network calls)
// -- neither has a test seam to fake through, matching this same
// session's PemCatalog/CoreUpdateService findings for the same class of
// "talks to piwigo.org" code. The one branch reachable without any of
// that is send()'s own CurrentConfig::sendPiwigoInfos() guard, which
// short-circuits before the DB reload or any network call -- reached
// right after send()'s own unconditional CurrentLogger::get() read, so
// this suite still needs a real (OFF-severity, side-effect-free) logger
// seeded first.

afterEach(function (): void {
    CurrentConfig::setSendPiwigoInfos(true);
});

test('send returns immediately without touching the DB or network when telemetry is disabled', function (): void {
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger(['severity' => Logger::OFF]));
    CurrentConfig::setSendPiwigoInfos(false);

    // No exception, no fatal, no side effect to assert beyond "returned" --
    // proven by simply completing without the DB-reload/network code below
    // it ever running (both would throw or hang in this sandboxed test
    // environment if reached).
    // Never actually read -- send() returns before touching ConfigService,
    // per this file's own docblock -- so a throwaway instance (no
    // Kernel::boot() needed; EntityManagerFactory::build() only
    // constructs objects, it never opens a real connection) is enough.
    $configService = new \Piwigo\Config\ConfigService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Config\ConfigEntry::class),
        new \Piwigo\PluginConfig\EventDispatcher(),
    );
    new PiwigoInfosSender($currentLogger, new ImageStdParams(), $configService)->send();

    expect(true)->toBeTrue();
});
