<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * currentConfig() resolves CurrentConfig directly from Kernel::container()
 * with no not-booted fallback of its own (same shape as pemUrl()'s own
 * currentConfig()-through call -- see RequestBootstrapPemUrlTest's
 * docblock) -- this covers its own defensive instanceof guard, which
 * nothing else in this suite reaches directly.
 */
test('currentConfig throws when the container returns an unexpected type', function (): void {
    // Kills line 1005's InstanceOfToTrue (`if (!true)`, never taking the
    // throw branch) -- same KernelContainerOverride::withWrongTypeFor()
    // pattern as InstallBootstrapTest's own "unexpected type" coverage of
    // this identical guard shape.
    KernelContainerOverride::withWrongTypeFor(
        CurrentConfig::class,
        static fn (): CurrentConfig => RequestBootstrap::currentConfig(),
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CurrentConfig::class);
