<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\GeoIp\GeoIpDatabaseUpdater;
use Piwigo\GeoIp\GeoIpLookupService;

/**
 * downloadMonth() reads $this->currentLogger->get() on a failed
 * download -- a bare `new CurrentLogger()` throws "CurrentLogger not
 * initialised" the moment that happens, same gap PemCatalogTest.php's
 * own identically-purposed helper works around.
 */
function geoIpUpdaterReadyLogger(): CurrentLogger
{
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger([
        'severity' => Logger::OFF,
    ]));

    return $currentLogger;
}

// Same real `php -S 127.0.0.1:<port>` + matching $_SERVER['HTTP_HOST']
// technique HttpClientServiceTest.php/PemCatalogTest.php both establish:
// HttpClientService's SSRF guard is https-only for a genuinely external
// target, and only exempts a request whose host[:port] matches the
// current $_SERVER['HTTP_HOST'] (a same-host self-request). Setting
// HTTP_HOST to the local test server's own host:port is what legitimately
// allows the plain http:// request through, real guard and all -- not a
// bypass.

/**
 * @return array{0: resource, 1: int}
 */
function geoIpUpdaterStartLocalServer(string $docRoot): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(20_000, 60_000);
        $proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $docRoot], $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('failed to start local test server');
        }

        set_error_handler(static fn (): bool => true);
        try {
            for ($i = 0; $i < 100; $i++) {
                $status = proc_get_status($proc);
                if (! $status['running']) {
                    break;
                }
                $sock = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if (is_resource($sock)) {
                    fclose($sock);

                    return [$proc, $port];
                }
                usleep(20_000);
            }
        } finally {
            restore_error_handler();
        }

        proc_terminate($proc);
        proc_close($proc);
    }

    throw new RuntimeException('local test server never became reachable after 5 attempts');
}

/**
 * @param resource $proc
 */
function geoIpUpdaterStopLocalServer($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
}

/**
 * @return array{root: string, docRoot: string, currentMonth: string}
 */
function geoIpUpdaterTestDirs(): array
{
    $root = sys_get_temp_dir() . '/piwigo-geoip-updater-test-' . bin2hex(random_bytes(8)) . '/';
    $docRoot = $root . 'docroot/';
    mkdir($docRoot, 0o777, true);

    return [
        'root' => $root,
        'docRoot' => $docRoot,
        'currentMonth' => new DateTimeImmutable()
            ->format('Y-m'),
    ];
}

function geoIpUpdaterTestPaths(string $root): Paths
{
    return new Paths(
        root: $root,
        plugins: $root . 'plugins/',
        themes: $root . 'themes/',
        local: $root . 'local/',
        siteLocal: $root . 'local/',
        data: $root . 'data/',
        derivatives: $root . 'data/i/',
        logs: $root . 'data/logs/',
        upload: $root . 'upload/',
        config: $root . 'config/',
        vendor: $root . 'vendor/',
    );
}

it('downloads, decompresses, and installs the current month\'s database', function (): void {
    ['root' => $root, 'docRoot' => $docRoot, 'currentMonth' => $currentMonth] = geoIpUpdaterTestDirs();
    file_put_contents($docRoot . "dbip-city-lite-{$currentMonth}.mmdb.gz", gzencode('fake mmdb payload', 9));

    [$proc, $port] = geoIpUpdaterStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;

    try {
        $paths = geoIpUpdaterTestPaths($root);
        $updater = new GeoIpDatabaseUpdater(
            $paths,
            new CurrentConfig(),
            geoIpUpdaterReadyLogger(),
            'http://127.0.0.1:' . $port . '/dbip-city-lite-%s.mmdb.gz',
        );

        expect($updater->update())
            ->toBeTrue();

        $installed = GeoIpLookupService::databasePathFor($paths);
        expect(is_file($installed))
            ->toBeTrue();
        expect(file_get_contents($installed))
            ->toBe('fake mmdb payload');
    } finally {
        geoIpUpdaterStopLocalServer($proc);
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
    }
});

it('falls back to last month\'s release when the current month is not published yet', function (): void {
    ['root' => $root, 'docRoot' => $docRoot] = geoIpUpdaterTestDirs();
    $lastMonth = new DateTimeImmutable()
        ->modify('-1 month')
        ->format('Y-m');
    // Deliberately no file for the current month -- only last month's,
    // simulating the real gap DB-IP has near the start of a month.
    file_put_contents($docRoot . "dbip-city-lite-{$lastMonth}.mmdb.gz", gzencode('last month payload', 9));

    [$proc, $port] = geoIpUpdaterStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;

    try {
        $paths = geoIpUpdaterTestPaths($root);
        $updater = new GeoIpDatabaseUpdater(
            $paths,
            new CurrentConfig(),
            geoIpUpdaterReadyLogger(),
            'http://127.0.0.1:' . $port . '/dbip-city-lite-%s.mmdb.gz',
        );

        expect($updater->update())
            ->toBeTrue();
        expect(file_get_contents(GeoIpLookupService::databasePathFor($paths)))->toBe('last month payload');
    } finally {
        geoIpUpdaterStopLocalServer($proc);
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
    }
});

it('leaves an existing database untouched when both months fail to download', function (): void {
    ['root' => $root, 'docRoot' => $docRoot] = geoIpUpdaterTestDirs();
    // No files served at all -- every request 404s.

    $paths = geoIpUpdaterTestPaths($root);
    $existing = GeoIpLookupService::databasePathFor($paths);
    mkdir(dirname($existing), 0o777, true);
    file_put_contents($existing, 'previously installed database');

    [$proc, $port] = geoIpUpdaterStartLocalServer($docRoot);
    $originalHost = $_SERVER['HTTP_HOST'] ?? null;
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . $port;

    try {
        $updater = new GeoIpDatabaseUpdater(
            $paths,
            new CurrentConfig(),
            geoIpUpdaterReadyLogger(),
            'http://127.0.0.1:' . $port . '/dbip-city-lite-%s.mmdb.gz',
        );

        expect($updater->update())
            ->toBeFalse();
        expect(file_get_contents($existing))
            ->toBe('previously installed database');
    } finally {
        geoIpUpdaterStopLocalServer($proc);
        if ($originalHost === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $originalHost;
        }
    }
});
