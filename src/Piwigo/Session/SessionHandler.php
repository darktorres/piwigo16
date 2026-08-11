<?php

declare(strict_types=1);

namespace Piwigo\Session;

use Override;
use Piwigo\Core\CurrentLogger;
use SessionHandlerInterface;
use Throwable;

// see https://php.watch/versions/8.4/session_set_save_handler-alt-signature-deprecated
final readonly class SessionHandler implements SessionHandlerInterface
{
    public function __construct(
        private SessionService $service,
        private CurrentLogger $currentLogger,
    ) {}

    #[Override]
    public function open(string $path, string $name): bool
    {
        return $this->service
            ->sessionOpen();
    }

    #[Override]
    public function close(): bool
    {
        return $this->service
            ->sessionClose();
    }

    #[Override]
    public function read(string $id): string
    {
        return $this->service
            ->sessionRead($id);
    }

    /**
     * PHP calls this method directly from its own internal session
     * module, both for an explicit session_write_close() call and for
     * PHP's own automatic end-of-script session close -- the latter runs
     * during the engine's own internal shutdown sequence, a code path no
     * caller-side try/catch (e.g. around an explicit session_write_close()
     * call) can ever reach, since PHP's own session module has no try/
     * catch around its call into this method either. A real DriverException
     * escaping uncaught from here becomes an unrecoverable PHP fatal
     * error regardless of which of those two paths triggered it -- caught
     * here instead, at the one method both paths funnel through, and
     * reported via SessionHandlerInterface's own well-defined "write
     * failed" contract (a false return) rather than a crash. A failed
     * final session persist is recoverable (the user just re-authenticates
     * on their next request); a fatal error escaping the engine's own
     * shutdown sequence isn't.
     */
    #[Override]
    public function write(string $id, string $data): bool
    {
        try {
            return $this->service
                ->sessionWrite($id, $data);
        } catch (Throwable $e) {
            // This method's own PHP-shutdown-driven invocation path (see
            // this method's own docblock) can fire after the request's
            // CurrentLogger has already gone out of scope -- confirmed
            // live via a full composer test:integration run, where a
            // deferred session auto-close landed inside a *later* test's
            // window after the earlier test's own teardown had already
            // reset it, and this fallback ->get() itself threw
            // "CurrentLogger not initialised", escaping uncaught exactly
            // the way this whole try/catch exists to prevent. A failed
            // best-effort log write is strictly better than that.
            if ($this->currentLogger->isInitialized()) {
                $this->currentLogger->get()
                    ->warn('SessionHandler::write() failed: ' . $e->getMessage());
            }

            return false;
        }
    }

    #[Override]
    public function destroy(string $id): bool
    {
        return $this->service
            ->sessionDestroy($id);
    }

    #[Override]
    public function gc(int $max_lifetime): int
    {
        return $this->service
            ->sessionGc();
    }
}
