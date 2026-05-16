<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Piwigo\Event\User\TryLogUser;
use Piwigo\Users\AuthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Default authentication path for the try_log_user filter event:
 * verifies the credential pair via AuthService::pwgLogin and flips
 * $event->success when the login succeeds.
 *
 * Legacy registered this in CommonBootstrap:
 *   EventDispatcher::addListener('try_log_user',
 *       Kernel::service(AuthService::class)->pwgLogin(...));
 * Replacing the singleton-via-Kernel access with constructor DI.
 */
final readonly class TryLogUserSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AuthService $authService,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [TryLogUser::class => 'onTryLogUser'];
    }

    public function onTryLogUser(TryLogUser $event): void
    {
        $event->success = $this->authService->pwgLogin(
            $event->success,
            $event->username,
            $event->password,
            $event->rememberMe,
        );
    }
}
