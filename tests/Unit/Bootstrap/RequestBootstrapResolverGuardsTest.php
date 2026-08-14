<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ServerTiming;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Users\CurrentUser;

/**
 * RequestBootstrap's own resolver accessor methods (lang()/pageState()/
 * currentUser()/currentConfigService()/serverTiming()/sessionService())
 * each carry an identical `if (! $x instanceof X) throw new
 * LogicException(...)` guard -- a real container binding always produces
 * a real instance of the type it's keyed on, so each guard is otherwise
 * unreachable through the public API. Same KernelContainerOverride::
 * withWrongTypeFor() pattern already established for currentConfig() in
 * RequestBootstrapCurrentConfigTest.php.
 */
test('lang throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        Lang::class,
        static fn (): Lang => RequestBootstrap::lang(),
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . Lang::class);

test('pageState throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        PageState::class,
        static fn (): PageState => RequestBootstrap::pageState(),
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . PageState::class);

test('currentUser throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CurrentUser::class,
        static fn (): CurrentUser => RequestBootstrap::currentUser(),
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CurrentUser::class);

test('currentConfigService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        CurrentConfigService::class,
        static fn (): CurrentConfigService => RequestBootstrap::currentConfigService(),
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . CurrentConfigService::class);

test('sessionService throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        SessionService::class,
        static fn (): SessionService => RequestBootstrap::sessionService(),
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . SessionService::class);

test('serverTiming throws when the container returns an unexpected type', function (): void {
    // Private (unlike the 5 public accessors above): every real caller
    // reaches it internally via bootConfigOnly()/configure()/
    // bootEntryPoint(), never directly -- Reflection isolates the guard
    // from those callers' own additional side effects/collaborators.
    $method = new ReflectionMethod(RequestBootstrap::class, 'serverTiming');

    KernelContainerOverride::withWrongTypeFor(
        ServerTiming::class,
        static fn (): mixed => $method->invoke(null),
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . ServerTiming::class);
