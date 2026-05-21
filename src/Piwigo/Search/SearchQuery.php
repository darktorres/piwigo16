<?php

declare(strict_types=1);

namespace Piwigo\Search;

/**
 * Typed representation of a persisted regular-search rule set.
 * Replaces the `array{fields?: array<string, mixed>, mode?: string}&array<string, mixed>`
 * intersection type that was the entrypoint for SearchRules::fromArray().
 *
 * `$fields` is intentionally public and mutable: SearchFilterRenderer mutates
 * it per-filter to drop inaccessible blocks and inject the user-visible state
 * that the hidden form re-emits when the user refines the search.
 */
final class SearchQuery
{
    /**
     * @param array<string, mixed> $fields  Per-filter input values (allwords, author, …)
     */
    public function __construct(
        public array $fields = [],
        public string $mode = 'AND',
        public int $version = 1,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            fields:  is_array($raw['fields'] ?? null) ? $raw['fields'] : [],
            mode:    is_string($raw['mode'] ?? null) ? $raw['mode'] : 'AND',
            version: is_int($raw['version'] ?? null) ? $raw['version'] : 1,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['fields' => $this->fields, 'mode' => $this->mode, 'version' => $this->version];
    }
}
