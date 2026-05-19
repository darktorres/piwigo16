<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

use Piwigo\Search\MalformedSearchRulesException;

/**
 * Versioned typed wrapper around the JSON `rules` blob stored in the
 * `search` table.
 *
 * Foundation step (F5-i): exposes the canonical `fromJson` / `fromArray`
 * entry points plus a {@see toJson()} serializer that always emits the
 * current version. The 15 nested filter VOs from the plan
 * (ExpertFilter, AllwordsFilter, CatFilter, …) and their typed
 * adoption inside `SearchService::getRegularSearchResults()` follow in
 * incremental F5-i sub-steps once the entity foundations exist.
 *
 * For now the `filters` slot stays as an untyped `array<string, mixed>`
 * so existing callers keep working while the typed aggregate grows.
 */
final readonly class SearchRules
{
    public const int CURRENT_VERSION = 1;

    /**
     * @param array<string, mixed> $filters Raw filter map keyed by the legacy filter name
     *                                      (`expert`, `allwords`, `cat`, `date_posted`, …). Will
     *                                      be replaced by typed VOs in follow-up F5-i sub-steps.
     */
    public function __construct(
        public int $version,
        public array $filters,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            throw new MalformedSearchRulesException('rules JSON is not an object');
        }
        // Filter keys are strings; a JSON array (numeric keys) is malformed.
        if ($raw !== [] && array_is_list($raw)) {
            throw new MalformedSearchRulesException('rules JSON is a list, expected an object');
        }
        /** @var array<string, mixed> $raw */
        return self::fromArray($raw);
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $version = is_int($raw['version'] ?? null) ? $raw['version'] : self::CURRENT_VERSION;
        return match ($version) {
            self::CURRENT_VERSION => self::fromV1($raw),
            default => throw new MalformedSearchRulesException("unsupported search rules version {$version}"),
        };
    }

    /** Encode back to JSON, always emitting the current schema version. */
    public function toJson(): string
    {
        $payload = array_merge($this->filters, ['version' => self::CURRENT_VERSION]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        return $encoded;
    }

    /**
     * Parse a v1 payload. v1 is the historical shape: a flat map keyed by
     * filter name, plus an optional `version` key. Unknown keys flow through
     * to {@see $filters} so plugins / legacy storage keep working.
     *
     * @param array<string, mixed> $raw
     */
    private static function fromV1(array $raw): self
    {
        $filters = $raw;
        unset($filters['version']);
        return new self(version: 1, filters: $filters);
    }
}
