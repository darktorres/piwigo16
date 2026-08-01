<?php

declare(strict_types=1);

use Piwigo\Bootstrap\SentryBootstrap;
use Sentry\SentrySdk;

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

test('resolveOptions returns null when SENTRY_DSN is not set or empty, matching init()\'s own no-op guard', function (): void {
    expect(SentryBootstrap::resolveOptions())->toBeNull();

    putenv('SENTRY_DSN=');
    expect(SentryBootstrap::resolveOptions())->toBeNull();
});

test('resolveOptions resolves an unset traces_sample_rate/environment (getenv() === false) to null, not a coerced value', function (): void {
    // Not (as a mutated `!== false` boundary, `&&`/`||`, or a mutated
    // `false` literal could each independently cause) a real
    // `(float) false === 0.0` or `(string) false === ''` value silently
    // reaching Options.
    putenv('SENTRY_DSN=https://fake@fake.ingest.sentry.io/1');

    $options = SentryBootstrap::resolveOptions();
    if ($options === null) {
        throw new LogicException('expected resolveOptions() to return a real array for a valid DSN');
    }

    expect($options['dsn'])->toBe('https://fake@fake.ingest.sentry.io/1')
        ->and($options['traces_sample_rate'])->toBeNull()
        ->and($options['environment'])->toBeNull();
});

test('resolveOptions resolves an explicitly empty-string traces_sample_rate/environment to null too, a distinct sentinel from unset', function (): void {
    // getenv() returning a real '' rather than false -- the '' sentinel
    // comparison is its own separate mutable literal from the `false` one
    // in the sibling test above.
    putenv('SENTRY_DSN=https://fake@fake.ingest.sentry.io/1');
    putenv('SENTRY_TRACES_SAMPLE_RATE=');
    putenv('SENTRY_ENVIRONMENT=');

    $options = SentryBootstrap::resolveOptions();
    if ($options === null) {
        throw new LogicException('expected resolveOptions() to return a real array for a valid DSN');
    }

    expect($options['traces_sample_rate'])->toBeNull()
        ->and($options['environment'])->toBeNull();
});

test('resolveOptions casts a real, non-empty traces_sample_rate to float and passes environment through as-is', function (): void {
    putenv('SENTRY_DSN=https://fake@fake.ingest.sentry.io/1');
    putenv('SENTRY_TRACES_SAMPLE_RATE=0.75');
    putenv('SENTRY_ENVIRONMENT=testing');

    $options = SentryBootstrap::resolveOptions();

    expect($options)->toBe([
        'dsn' => 'https://fake@fake.ingest.sentry.io/1',
        'traces_sample_rate' => 0.75,
        'environment' => 'testing',
    ]);
});

// No test here calls the real \Sentry\init() (via SentryBootstrap::init())
// with a valid DSN: that call registers real global PHP error/exception
// handlers via the SDK's own integrations, and doing it more than once
// across the WHOLE Unit+Arch suite -- even in a totally different file,
// even with a real restore_error_handler()/restore_exception_handler()
// after each -- leaves the SDK's own internal handler-chain state
// imbalanced in a way PHPUnit's risky-test detector correctly notices
// (confirmed live: exactly one real init()+restore anywhere in the whole
// `composer test` run is never flagged; a second one anywhere else always
// is, regardless of which file). RequestBootstrapBootConfigOnlyTest.php's
// own "initializes Sentry when SENTRY_DSN is set" test already is that
// one real touch (bootConfigOnly() calls SentryBootstrap::init()
// directly) -- proving the real SDK binds a client for a valid DSN, which
// transitively covers this class's own init()/\Sentry\init() wiring.
// Every env-var-resolution edge case (unset/empty-string/real-value) is
// covered above via resolveOptions() directly instead, which never
// touches the real SDK at all.
