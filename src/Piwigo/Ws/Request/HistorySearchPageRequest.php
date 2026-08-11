<?php

declare(strict_types=1);

namespace Piwigo\Ws\Request;

/**
 * Validated `$_GET['start']` for `Core::historySearch()`.
 * This is a raw pagination offset independent of that
 * method's own WS-registered `$param` array (which has its own,
 * differently-named `start`/`end` date-range search fields) -- a known
 * legacy quirk of this method doubling as both a WS API endpoint and the
 * admin history-search page's own result-pagination logic. No pattern
 * validation needed beyond numeric coercion: any non-numeric/absent value
 * already fell back to offset 0 before this DTO existed.
 */
final readonly class HistorySearchPageRequest
{
    private function __construct(
        public int $start,
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
        $start = $source['start'] ?? null;

        return new self(is_numeric($start) ? (int) $start : 0);
    }
}
