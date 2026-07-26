<?php

declare(strict_types=1);

namespace Piwigo\Template\Request;

/**
 * Validated `$_GET` shape for Template::set_extents() -- P27/SEC-40
 * Request DTO. The original matches a candidate extension's own `$param`
 * substring against every GET param NAME concatenated together (never
 * the values) -- a legacy PATH_INFO-style query-string convention (e.g.
 * `?admin/foo=1`), not user-controlled data flowing anywhere sensitive.
 * `keysConcatenated` is the same `implode('', array_keys($_GET))`
 * precomputed once.
 */
final readonly class TemplateExtentsRequest
{
    private function __construct(
        public string $keysConcatenated,
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
        return new self(implode('', array_map(strval(...), array_keys($get))));
    }
}
