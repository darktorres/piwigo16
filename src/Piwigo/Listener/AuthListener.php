<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Override;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\Event\TryLogUser;
use Piwigo\Core\SubscriberInterface;

/**
 * Extracted from `Bootstrap\RequestBootstrap::connect()`'s own
 * `TryLogUser` registration. Registered early, in `connect()`, before
 * `UserBootstrap::initialize()` -- `initialize()` reaches
 * `AuthService::tryLogUser()` directly on its own
 * `pwg.images.uploadAsync` username/password credential path before
 * `finalize()` (where every other real default handler registers) ever
 * runs, and `EventDispatcher::triggerChange()` with no matching handler
 * returns its own default unmodified, so that credential path needs the
 * handler registered this early. `RequestBootstrap` constructs this
 * listener explicitly (not via container autowiring) so `$authService`
 * reuses the request's own shared `Connection` instead of building a
 * separate one.
 */
final readonly class AuthListener implements SubscriberInterface
{
    public function __construct(
        private AuthService $authService,
    ) {}

    #[Override]
    public function subscribedEvents(): array
    {
        return [
            TryLogUser::class => $this->onTryLogUser(...),
        ];
    }

    public function onTryLogUser(TryLogUser $event): TryLogUser
    {
        return $this->authService->pwgLogin($event);
    }
}
