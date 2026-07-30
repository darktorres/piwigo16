<?php

declare(strict_types=1);

namespace Piwigo\Session;

// see https://php.watch/versions/8.4/session_set_save_handler-alt-signature-deprecated
final class PwgSession implements \SessionHandlerInterface
{
    public function __construct(
        private readonly ?SessionService $service = null,
    ) {}

    /**
     * Resolved on every call rather than captured once in the constructor
     * -- session_set_save_handler() registers one PwgSession instance for
     * the rest of the process, so capturing SessionService::get()'s
     * singleton up front would freeze this handler onto whatever
     * connection/database was current at registration time, surviving
     * (and breaking) any later test-isolation reset of that singleton in
     * the same process (e.g. a test process running several Integration
     * test classes back to back).
     */
    private function service(): SessionService
    {
        return $this->service ?? SessionService::get();
    }

    #[\Override]
    public function open(string $path, string $name): bool
    {
        return $this->service()
            ->sessionOpen();
    }

    #[\Override]
    public function close(): bool
    {
        return $this->service()
            ->sessionClose();
    }

    #[\Override]
    public function read(string $id): string
    {
        return $this->service()
            ->sessionRead($id);
    }

    #[\Override]
    public function write(string $id, string $data): bool
    {
        return $this->service()
            ->sessionWrite($id, $data);
    }

    #[\Override]
    public function destroy(string $id): bool
    {
        return $this->service()
            ->sessionDestroy($id);
    }

    #[\Override]
    public function gc(int $max_lifetime): int
    {
        return $this->service()
            ->sessionGc();
    }
}
