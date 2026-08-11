<?php

declare(strict_types=1);

namespace Piwigo\Ws\Request;

/**
 * Validated `$_FILES[$key]` shape for Images::addSimple()/upload()/
 * uploadAsync(). `Server::invoke()`'s own
 * `call_user_func_array($method['callback'], [$params, &$this])` dispatch
 * (confirmed by reading it directly) calls every `Ws\*` method with
 * exactly those 2 fixed arguments, so `$_FILES` can't be threaded in as
 * a 3rd parameter the way a page controller's own DTO is -- constructed
 * inside each method's own body instead, same as every other Request DTO
 * this migration has built.
 */
final readonly class UploadedFileRequest
{
    private function __construct(
        public bool $present,
        public ?int $error,
        public ?string $tmpName,
        public ?string $name,
    ) {}

    public static function fromFilesKey(string $key): self
    {
        $files = $_FILES[$key] ?? null;
        return self::fromArray(is_array($files) ? $files : null);
    }

    /**
     * @param array<int|string, mixed>|null $file
     */
    public static function fromArray(?array $file): self
    {
        if ($file === null) {
            return new self(false, null, null, null);
        }

        $error_raw = $file['error'] ?? null;
        $tmp_name_raw = $file['tmp_name'] ?? null;
        $name_raw = $file['name'] ?? null;

        return new self(
            true,
            is_int($error_raw) ? $error_raw : null,
            is_string($tmp_name_raw) ? $tmp_name_raw : null,
            is_string($name_raw) ? $name_raw : null,
        );
    }
}
