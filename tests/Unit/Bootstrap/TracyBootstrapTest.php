<?php

declare(strict_types=1);

use Piwigo\Bootstrap\TracyBootstrap;
use Tracy\Debugger;

/**
 * Tracy\Debugger::$enabled has no public reset -- unlike Sentry's own
 * SentrySdk::init() (a real, published reset hook SentryBootstrapTest.php
 * relies on), once ANY test anywhere in this worker process calls
 * Debugger::enable() for real, isEnabled() stays true for the rest of that
 * worker's lifetime. Reflection-based save/restore around each test below
 * keeps these no-op assertions isolated from that shared, sticky state --
 * confirmed live: without it, running this file after
 * RequestBootstrapBootConfigOnlyTest.php's own real-touch test in the same
 * parallel worker makes both tests below fail on a stale `true`.
 */
function tracyBootstrapTestEnabledProperty(): ReflectionProperty
{
    return new ReflectionProperty(Debugger::class, 'enabled');
}

beforeEach(function (): void {
    putenv('PIWIGO_TRACY_ENABLED');
    $this->originalEnabled = tracyBootstrapTestEnabledProperty()
        ->getValue();
    tracyBootstrapTestEnabledProperty()
        ->setValue(null, false);
});

afterEach(function (): void {
    putenv('PIWIGO_TRACY_ENABLED');
    tracyBootstrapTestEnabledProperty()
        ->setValue(null, $this->originalEnabled);
});

test('init is a no-op when PIWIGO_TRACY_ENABLED is not set', function (): void {
    TracyBootstrap::init();

    expect(Debugger::isEnabled())->toBeFalse();
});

test('init is a no-op when PIWIGO_TRACY_ENABLED is an empty string', function (): void {
    putenv('PIWIGO_TRACY_ENABLED=');

    TracyBootstrap::init();

    expect(Debugger::isEnabled())->toBeFalse();
});

// No test here calls the real Debugger::enable() (via TracyBootstrap::init()
// with PIWIGO_TRACY_ENABLED set): that call registers real global PHP
// error/exception/shutdown handlers, and doing it more than once across the
// whole Unit+Arch suite -- even in a totally different file -- leaves
// Tracy's own internal handler-chain state imbalanced in a way PHPUnit's
// risky-test detector correctly notices (same reasoning already established
// for Sentry, see SentryBootstrapTest.php's own closing comment).
// RequestBootstrapBootConfigOnlyTest.php's own "initializes Tracy when
// PIWIGO_TRACY_ENABLED is set" test is that one real touch (bootConfigOnly()
// calls TracyBootstrap::init() directly), proving the real SDK actually
// enables -- which transitively covers this class's own init()/
// Debugger::enable() wiring. The env-var-resolution logic itself
// (unset/empty/"0"/real-value) is covered by Env::isTracyEnabled()'s own
// tests in EnvTest.php instead, which never touch Tracy at all.
