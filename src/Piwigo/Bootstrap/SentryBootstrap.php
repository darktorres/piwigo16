<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

/**
 * Initializes the Sentry PHP SDK. Explicit early-return no-op when no DSN
 * is configured -- defense in depth on top of the SDK's own internal guard
 * (Transport\HttpTransport::send() checks getDsn() === null before any
 * network I/O; verified directly in the installed vendor/sentry/sentry
 * source, not assumed). Reads env vars via plain getenv() -- P2's
 * pwg_load_env_file() (include/env.inc.php, called from common.inc.php)
 * already populates them via symfony/dotenv's usePutenv() before
 * CommonBootstrap::run() executes on every real request; no new
 * env-loading mechanism needed here.
 */
final class SentryBootstrap
{
    public static function init(): void
    {
        $dsn = getenv('SENTRY_DSN');
        if ($dsn === false || $dsn === '') {
            return;
        }

        $sampleRate = getenv('SENTRY_TRACES_SAMPLE_RATE');
        $environment = getenv('SENTRY_ENVIRONMENT');

        \Sentry\init([
            'dsn' => $dsn,
            'traces_sample_rate' => $sampleRate !== false && $sampleRate !== '' ? (float) $sampleRate : null,
            'environment' => $environment !== false && $environment !== '' ? $environment : null,
        ]);
    }
}
