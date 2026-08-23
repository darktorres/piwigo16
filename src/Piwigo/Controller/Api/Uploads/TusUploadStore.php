<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;

/**
 * Filesystem-backed store for in-progress tus uploads -- one `.data` file
 * (the growing byte buffer, appended to at each `PATCH`) and one `.json`
 * sidecar (the `TusUploadSession` metadata) per upload, under
 * `<uploadDir>/buffer/tus/`. Assumes this buffer directory is always
 * local disk -- `PATCH`'s append-at-offset semantics need real
 * `fseek()`, not a portable `StorageRegistry` write, so this
 * deliberately bypasses it.
 */
final readonly class TusUploadStore
{
    public function __construct(
        private Paths $paths,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * @param array<string, string> $metadata parsed `Upload-Metadata` header
     */
    public function create(int $uploadLength, int $userId, array $metadata): ?TusUploadSession
    {
        if (! FilesystemHelper::mkgetdir($this->bufferDir(), $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
            return null;
        }

        $id = bin2hex(random_bytes(16));
        $session = TusUploadSession::create($id, $uploadLength, $userId, $metadata);

        if (file_put_contents($this->dataPath($id), '') === false) {
            return null;
        }

        if (file_put_contents($this->metaPath($id), json_encode($session->toArray(), JSON_THROW_ON_ERROR)) === false) {
            @unlink($this->dataPath($id));

            return null;
        }

        return $session;
    }

    public function find(string $id): ?TusUploadSession
    {
        if (! self::isValidId($id)) {
            return null;
        }

        $raw = @file_get_contents($this->metaPath($id));
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return TusUploadSession::fromArray($decoded);
    }

    /**
     * Same as `find()`, plus the ownership check every `HEAD`/`PATCH`/
     * `DELETE` needs -- folded in here rather than left to each caller, so
     * the check can't be forgotten at a new call site.
     */
    public function findOwnedBy(string $id, int $userId): ?TusUploadSession
    {
        $session = $this->find($id);

        return $session instanceof TusUploadSession && $session->createdByUserId === $userId ? $session : null;
    }

    public function offset(string $id): ?int
    {
        if (! self::isValidId($id)) {
            return null;
        }

        $size = @filesize($this->dataPath($id));

        return $size === false ? null : $size;
    }

    /**
     * Appends `$stream`'s remaining contents to the upload's data file,
     * but only if `$expectedOffset` matches the file's real current size
     * (tus's own conflict rule) -- checked and written under one
     * exclusive lock, closing the race a separate size-check-then-append
     * would leave open between two concurrent `PATCH`es for the same
     * upload id. Returns the new offset, or null on a missing upload, an
     * offset mismatch, or a write that would exceed $uploadLength.
     *
     * $uploadLength is a defensive cap, not the primary defense --
     * TusUploadPatchController already rejects an over-length PATCH up
     * front via the Content-Length header, before ever calling this
     * method. This only protects the on-disk invariant itself in case
     * that header ever undercounts the real body.
     *
     * @param resource $stream
     */
    public function appendChunk(string $id, int $expectedOffset, $stream, int $uploadLength): ?int
    {
        $path = $this->dataPath($id);
        if (! self::isValidId($id) || ! is_file($path)) {
            return null;
        }

        $out = fopen($path, 'r+b');
        if ($out === false) {
            return null;
        }

        if (! flock($out, LOCK_EX)) {
            fclose($out);

            return null;
        }

        $stat = fstat($out);
        $currentSize = $stat === false ? null : $stat['size'];
        if ($currentSize !== $expectedOffset) {
            flock($out, LOCK_UN);
            fclose($out);

            return null;
        }

        $maxWrite = max(0, $uploadLength - $expectedOffset);

        fseek($out, 0, SEEK_END);
        $written = 0;
        $overshoot = false;
        while (! feof($stream)) {
            $remaining = $maxWrite - $written;
            if ($remaining <= 0) {
                // Any further stream bytes would push the file past
                // $uploadLength -- peek one byte to tell "stream was
                // exactly at the cap" from "stream still has more".
                $peek = fread($stream, 1);
                $overshoot = is_string($peek) && $peek !== '';
                break;
            }

            $buffer = fread($stream, min(8192, $remaining));
            if ($buffer === false || $buffer === '') {
                break;
            }

            $bytesWritten = fwrite($out, $buffer);
            if ($bytesWritten === false) {
                break;
            }

            $written += $bytesWritten;
        }

        fflush($out);
        flock($out, LOCK_UN);
        fclose($out);

        return $overshoot ? null : $expectedOffset + $written;
    }

    public function dataFilePath(string $id): string
    {
        return $this->dataPath($id);
    }

    public function delete(string $id): void
    {
        if (! self::isValidId($id)) {
            return;
        }

        @unlink($this->dataPath($id));
        @unlink($this->metaPath($id));
    }

    private function bufferDir(): string
    {
        return $this->paths->root . $this->currentConfig->uploadDir . '/buffer/tus';
    }

    private function dataPath(string $id): string
    {
        return $this->bufferDir() . '/' . $id . '.data';
    }

    private function metaPath(string $id): string
    {
        return $this->bufferDir() . '/' . $id . '.json';
    }

    private static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
    }
}
