<?php

declare(strict_types=1);

namespace Piwigo\Session;

use Piwigo\Config\Config;

final class SessionService
{
    public function __construct(
        private readonly SessionRepository $repo,
    ) {}

    public function generateKey(int $size): string
    {
        $bytes = random_bytes(max(1, $size + 10));
        return substr(str_replace(['+', '/'], '', base64_encode($bytes)), 0, $size);
    }

    public function sessionOpen(string $path, string $name): bool
    {
        return true;
    }

    public function sessionClose(): bool
    {
        return true;
    }

    public function getRemoteAddrSessionHash(): string
    {
        if (!Config::sessionUseIpAddress()) {
            return '';
        }

        $remoteAddr = is_scalar($_SERVER['REMOTE_ADDR'] ?? null) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (!str_contains($remoteAddr, ':')) { // ipv4
            $parts = explode('.', $remoteAddr);
            if (count($parts) >= 2) {
                return vsprintf('%02X%02X', $parts);
            }
        }
        return '';
    }

    public function sessionRead(string $sessionId): string
    {
        return $this->repo->read($this->getRemoteAddrSessionHash() . $sessionId);
    }

    public function sessionWrite(string $sessionId, string $data): bool
    {
        if (defined('PWG_API_KEY_REQUEST')) {
            return true;
        }
        $this->repo->write($this->getRemoteAddrSessionHash() . $sessionId, $data);
        return true;
    }

    public function sessionDestroy(string $sessionId): bool
    {
        $this->repo->destroy($this->getRemoteAddrSessionHash() . $sessionId);
        return true;
    }

    public function sessionGc(): bool
    {
        $this->repo->gc(Config::sessionLength());
        return true;
    }

    public function setSessionVar(string $var, mixed $value): bool
    {
        if (!isset($_SESSION)) {
            return false;
        }
        $_SESSION['pwg_' . $var] = $value;
        return true;
    }

    public function getSessionVar(string $var, mixed $default = null): mixed
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
