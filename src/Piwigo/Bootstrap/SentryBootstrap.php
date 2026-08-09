<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use function Sentry\init;

/**
 * Initializes the Sentry PHP SDK. Explicit early-return no-op when no DSN
 * is configured -- defense in depth on top of the SDK's own internal guard
 * (Transport\HttpTransport::send() checks getDsn() === null before any
 * network I/O; verified directly in the installed vendor/sentry/sentry
 * source, not assumed). Reads env vars via plain getenv() --
 * pwg_load_env_file() (include/env.inc.php, called from common.inc.php)
 * already populates them via symfony/dotenv's usePutenv() before this
 * class's own init() runs (as RequestBootstrap::bootEntryPoint()'s first
 * statement) on every real request; no new env-loading mechanism needed
 * here.
 */
final class SentryBootstrap
{
    public static function init(): void
    {
        $options = self::resolveOptions();
        if ($options === null) {
            return;
        }

        init($options);
    }

    /**
     * Pure env-var resolution, split out from init() so it's testable
     * without a real \Sentry\init() call -- that call registers real
     * global PHP error/exception handlers via the SDK's own integrations,
     * and calling it more than once per test (even with a real
     * restore_error_handler()/restore_exception_handler() after each)
     * leaves the SDK's own internal handler-chain state imbalanced in a
     * way PHPUnit's risky-test detector correctly notices -- confirmed
     * live: a single real init()+restore is never flagged, a second one
     * in the same test always is, regardless of how many env-var/option
     * combinations are exercised. Only the "valid, real DSN" scenario
     * needs the real SDK call to prove the wiring actually binds a
     * client; the others are exercised through this method directly.
     *
     * @return array{dsn: string, traces_sample_rate: ?float, environment: ?string}|null null means "no-op, SENTRY_DSN unset/empty"
     */
    public static function resolveOptions(): ?array
    {
        $dsn = getenv('SENTRY_DSN');
        if ($dsn === false || $dsn === '') {
            return null;
        }

        $sampleRate = getenv('SENTRY_TRACES_SAMPLE_RATE');
        $environment = getenv('SENTRY_ENVIRONMENT');

        return [
            'dsn' => $dsn,
            'traces_sample_rate' => $sampleRate !== false && $sampleRate !== '' ? (float) $sampleRate : null,
            'environment' => $environment !== false && $environment !== '' ? $environment : null,
        ];
    }
}
