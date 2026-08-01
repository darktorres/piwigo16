<?php

declare(strict_types=1);

use Piwigo\Bootstrap\SentryBootstrap;
use Sentry\Options;
use Sentry\SentrySdk;

function sentryBootstrapTestBoundOptions(): Options
{
    $options = SentrySdk::getCurrentHub()->getClient()?->getOptions();
    if ($options === null) {
        throw new LogicException('Expected a client to be bound after SentryBootstrap::init()');
    }
    return $options;
}

beforeEach(function (): void {
    SentrySdk::init(); // fresh hub, no client bound
    putenv('SENTRY_DSN');
    putenv('SENTRY_TRACES_SAMPLE_RATE');
    putenv('SENTRY_ENVIRONMENT');
});

afterEach(function (): void {
    SentrySdk::init();
    putenv('SENTRY_DSN');
    putenv('SENTRY_TRACES_SAMPLE_RATE');
    putenv('SENTRY_ENVIRONMENT');
});

test('init is a no-op when SENTRY_DSN is not set', function (): void {
    SentryBootstrap::init();

    expect(SentrySdk::getCurrentHub()->getClient())->toBeNull();
});

test('init is a no-op when SENTRY_DSN is an empty string', function (): void {
    putenv('SENTRY_DSN=');

    SentryBootstrap::init();

    expect(SentrySdk::getCurrentHub()->getClient())->toBeNull();
});

test('init binds a client when SENTRY_DSN is set', function (): void {
    putenv('SENTRY_DSN=https://fake@fake.ingest.sentry.io/1');

    SentryBootstrap::init();

    expect(SentrySdk::getCurrentHub()->getClient())->not->toBeNull();

    // A real DSN makes init()'s default integrations register global PHP
    // error/exception handlers -- that's the real, intended production
    // behavior this test exercises on purpose. Restore them here (only
    // this test registers any) rather than unconditionally in afterEach,
    // which would pop PHPUnit's own harness handler on every other test
    // in this file that never touched one.
    restore_error_handler();
    restore_exception_handler();
});

test('init resolves the exact dsn/traces_sample_rate/environment across the valid, unset, and empty-string states', function (): void {
    // The 2 tests above only ever check *whether* a client got bound,
    // never the actual resolved \Sentry\Options values -- Symfony's
    // OptionsResolver type-checks traces_sample_rate as null|int|float
    // (`vendor/sentry/sentry/src/Options.php`'s own setAllowedTypes()),
    // so a real, non-empty SENTRY_TRACES_SAMPLE_RATE value going through
    // without the `(float)` cast throws rather than silently coercing --
    // confirmed live, not assumed.
    //
    // All 3 scenarios live in one test (each its own SentrySdk::init() +
    // SentryBootstrap::init() + restore_*_handler() cycle) rather than 3
    // separate test() blocks: PHPUnit's risky-test detector compares the
    // active error/exception handler at the *start* of each test method
    // against whatever is active at the end, and 2+ separate test()
    // blocks in this file each independently pushing/popping a real
    // Sentry-installed handler flags every one past the first as risky
    // (confirmed empirically -- which specific test runs first doesn't
    // matter, only that more than one does), even though each one's own
    // push/pop is individually balanced.

    // Valid, non-empty values for both.
    putenv('SENTRY_DSN=https://fake@fake.ingest.sentry.io/1');
    putenv('SENTRY_TRACES_SAMPLE_RATE=0.75');
    putenv('SENTRY_ENVIRONMENT=testing');
    SentryBootstrap::init();
    $options = sentryBootstrapTestBoundOptions();
    expect((string) $options->getDsn())->toBe('https://fake@fake.ingest.sentry.io/1')
        ->and($options->getTracesSampleRate())->toBe(0.75)
        ->and($options->getEnvironment())->toBe('testing');
    restore_error_handler();
    restore_exception_handler();

    // Unset (getenv() returns `false`) must resolve to `null`, not (as a
    // mutated `!== false` boundary, `&&`/`||`, or a mutated `false`
    // literal could each independently cause) a real `(float) false ===
    // 0.0` or `(string) false === ''` value silently reaching Options.
    SentrySdk::init();
    putenv('SENTRY_TRACES_SAMPLE_RATE');
    putenv('SENTRY_ENVIRONMENT');
    SentryBootstrap::init();
    $options = sentryBootstrapTestBoundOptions();
    expect($options->getTracesSampleRate())->toBeNull()
        ->and($options->getEnvironment())->toBeNull();
    restore_error_handler();
    restore_exception_handler();

    // A distinct env-var state from "unset" (getenv() returns a real ''
    // rather than false) -- the '' sentinel comparison is its own
    // separate mutable literal from the `false` one above.
    SentrySdk::init();
    putenv('SENTRY_TRACES_SAMPLE_RATE=');
    putenv('SENTRY_ENVIRONMENT=');
    SentryBootstrap::init();
    $options = sentryBootstrapTestBoundOptions();
    expect($options->getTracesSampleRate())->toBeNull()
        ->and($options->getEnvironment())->toBeNull();
    restore_error_handler();
    restore_exception_handler();
});
