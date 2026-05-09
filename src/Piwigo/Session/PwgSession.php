<?php

declare(strict_types=1);

namespace Piwigo\Session;

use Piwigo\Core\ServiceLocator;

// see https://php.watch/versions/8.4/session_set_save_handler-alt-signature-deprecated
final class PwgSession implements \SessionHandlerInterface
{
    #[\Override]
    public function open(string $path, string $name): bool
    {
        return ServiceLocator::get(SessionService::class)->sessionOpen($path, $name);
    }

    #[\Override]
    public function close(): bool
    {
        return ServiceLocator::get(SessionService::class)->sessionClose();
    }

    #[\Override]
    public function read(string $id): string
    {
        return ServiceLocator::get(SessionService::class)->sessionRead($id);
    }

    #[\Override]
    public function write(string $id, string $data): bool
    {
        return ServiceLocator::get(SessionService::class)->sessionWrite($id, $data);
    }

    #[\Override]
    public function destroy(string $id): bool
    {
        return ServiceLocator::get(SessionService::class)->sessionDestroy($id);
    }

    #[\Override]
    public function gc(int $max_lifetime): int
    {
        ServiceLocator::get(SessionService::class)->sessionGc();
        return 1;
    }
}
