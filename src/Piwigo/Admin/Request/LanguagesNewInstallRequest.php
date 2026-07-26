<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_GET['revision']`/`$_GET['installstatus']` for
 * LanguagesNewPageRenderer::render() (the "new" tab of page slug
 * "languages") -- P27/SEC-40 Request DTO. Neither needs pattern
 * validation: `revision` is only ever built from this page's own template
 * link (a numeric string) and is handed to PemCatalog::extractArchive()
 * with no further trust placed in its shape; `installstatus` is matched
 * against a `match` expression with a safe `default` branch for any
 * unrecognized value.
 */
final readonly class LanguagesNewInstallRequest
{
    private function __construct(
        public ?string $revision,
        public ?string $installStatus,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        $revision = $source['revision'] ?? null;
        $installstatus = $source['installstatus'] ?? null;

        return new self(
            isset($source['revision']) ? (is_string($revision) ? $revision : '') : null,
            isset($source['installstatus']) ? (is_string($installstatus) ? $installstatus : '') : null,
        );
    }
}
