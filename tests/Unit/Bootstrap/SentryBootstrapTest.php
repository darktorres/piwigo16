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
