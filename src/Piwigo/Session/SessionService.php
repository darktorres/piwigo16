<?php

declare(strict_types=1);

namespace Piwigo\Session;

use Piwigo\Config\Config;

final readonly class SessionService implements \SessionHandlerInterface
{
    public function __construct(
        private SessionRepository $repo,
    ) {
    }

    public function generateKey(int $size): string
    {
        $bytes = random_bytes(max(1, $size + 10));
        return substr(str_replace(['+', '/'], '', base64_encode($bytes)), 0, $size);
    }

    #[\Override]
    public function open(string $path, string $name): bool
    {
        return true;
    }

    #[\Override]
    public function close(): bool
    {
        return true;
    }

    public function getRemoteAddrSessionHash(): string
    {
        if (!Config::sessionUseIpAddress()) {
            return '';
        }

        /** @var mixed $remoteAddrRaw */
        $remoteAddrRaw = $_SERVER['REMOTE_ADDR'] ?? '';
        $remoteAddr    = is_string($remoteAddrRaw) ? $remoteAddrRaw : '';
        if (!str_contains($remoteAddr, ':')) { // ipv4
            $parts = explode('.', $remoteAddr);
            if (count($parts) >= 2) {
                return vsprintf('%02X%02X', $parts);
            }
        }
        return '';
    }

    #[\Override]
    public function read(string $id): string
    {
        return $this->repo->read($this->getRemoteAddrSessionHash() . $id);
    }

    #[\Override]
    public function write(string $id, string $data): bool
    {
        if (defined('PWG_API_KEY_REQUEST')) {
            return true;
        }
        $this->repo->write($this->getRemoteAddrSessionHash() . $id, $data);
        return true;
    }

    #[\Override]
    public function destroy(string $id): bool
    {
        $this->repo->destroy($this->getRemoteAddrSessionHash() . $id);
        return true;
    }

    #[\Override]
    public function gc(int $max_lifetime): int
    {
        $this->repo->gc(Config::sessionLength());
        return 1;
    }

    public function setSessionVar(string $var, mixed $value): bool
    {
        if (!isset($_SESSION)) {
            return false;
        }
        $_SESSION['pwg_' . $var] = $value;
        return true;
    }

    /**
     * @param (int|string)[]|false|int|null|string $default
     *
     * @psalm-param 0|array{user: 0, recent_period: -1, time: 0, date: ''}|false|null|string $default
     */
    public function getSessionVar(string $var, array|int|string|false|null $default = null): mixed
    {
        return $_SESSION['pwg_' . $var] ?? $default;
    }

    public function unsetSessionVar(string $var): bool
    {
        if (!isset($_SESSION)) {
            return false;
        }
        unset($_SESSION['pwg_' . $var]);
        return true;
    }

    public function deleteUserSessions(int $userId): void
    {
        $this->repo->deleteByUserId($userId);
    }
}
