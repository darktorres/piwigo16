<?php

declare(strict_types=1);

namespace Piwigo\Session;

use Piwigo\Db\DbConnection;

// see https://php.watch/versions/8.4/session_set_save_handler-alt-signature-deprecated
class PwgSession implements \SessionHandlerInterface
{
    private readonly SessionService $service;

    public function __construct(?SessionService $service = null)
    {
        $this->service = $service ?? new SessionService(new SessionRepository(DbConnection::build()));
    }

    #[\Override]
    public function open(string $path, string $name): bool
    {
        return $this->service->sessionOpen();
    }

    #[\Override]
    public function close(): bool
    {
        return $this->service->sessionClose();
    }

    #[\Override]
    public function read(string $id): string
    {
        return $this->service->sessionRead($id);
    }

    #[\Override]
    public function write(string $id, string $data): bool
    {
        return $this->service->sessionWrite($id, $data);
    }

    #[\Override]
    public function destroy(string $id): bool
    {
        return $this->service->sessionDestroy($id);
    }

    #[\Override]
    public function gc(int $max_lifetime): int
    {
        return $this->service->sessionGc();
    }
}
