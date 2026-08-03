<?php

declare(strict_types=1);

namespace Piwigo\Section\Request;

/**
 * Validated `$_GET` shape for SectionInitializer::parse() -- P26/SEC-40
 * Request DTO.
 *
 * `firstGetKey` is the legacy query-string-as-path convention this
 * gallery URL scheme relies on (`?category/12-name/start-24` --  the
 * whole "path" is encoded as a GET *key*, never a value, since no `=`
 * ever follows it) -- PHP auto-casts a purely-numeric key (e.g. `?1`) to
 * a real int array key, so this stays a string cast of the first key,
 * defaulting to `''` when `$_GET` is empty, matching the original's own
 * `foreach (...) { ...; break; }` loop exactly.
 */
final readonly class SectionUrlRequest
{
    private function __construct(
        public string $firstGetKey,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $get
     */
    public static function fromArray(array $get): self
    {
        foreach (array_keys($get) as $key) {
            return new self((string) $key);
        }

        return new self('');
    }
}
