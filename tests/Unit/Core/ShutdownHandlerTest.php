<?php

declare(strict_types=1);

use Piwigo\Core\ShutdownHandler;

// install() wires a real SIGTERM handler -- a P10-style global-state hazard
// (same lesson as that phase's Sentry default_integrations handler
// leakage), so afterEach restores SIGTERM to SIG_DFL to keep this from
// bleeding into unrelated tests in the same worker process.
//
// Actual signal delivery (a live SIGTERM arriving mid-command) is
// deliberately not exercised here -- the real handler calls exit(143),
// which would terminate the test worker itself. runAll() is invoked
// directly via reflection instead, verifying the callback-running
// mechanics without needing a real OS signal.

beforeEach(function (): void {
    ShutdownHandler::reset();
});

afterEach(function (): void {
    ShutdownHandler::reset();
    pcntl_signal(SIGTERM, SIG_DFL);
});

test('install() wires a real SIGTERM handler', function (): void {
    expect(pcntl_signal_get_handler(SIGTERM))->toBe(SIG_DFL);

    ShutdownHandler::install();

    expect(pcntl_signal_get_handler(SIGTERM))->toBeInstanceOf(Closure::class);
});

test('install() is idempotent', function (): void {
    ShutdownHandler::install();
    ShutdownHandler::install();

    expect(pcntl_signal_get_handler(SIGTERM))->toBeInstanceOf(Closure::class);
});

test('registered callbacks run when the signal handler fires', function (): void {
    $ran = [];
    ShutdownHandler::register(function () use (&$ran): void {
        $ran[] = 'first';
    });
    ShutdownHandler::register(function () use (&$ran): void {
        $ran[] = 'second';
    });

    $runAll = new ReflectionMethod(ShutdownHandler::class, 'runAll');
    $runAll->invoke(null);

    expect($ran)->toBe(['first', 'second']);
});

test('reset() clears registered callbacks', function (): void {
    $ran = false;
    ShutdownHandler::register(function () use (&$ran): void {
        $ran = true;
    });

    ShutdownHandler::reset();

    $runAll = new ReflectionMethod(ShutdownHandler::class, 'runAll');
    $runAll->invoke(null);

    expect($ran)->toBeFalse();
});
