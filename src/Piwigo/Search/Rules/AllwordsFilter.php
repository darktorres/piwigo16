<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `allwords` saved-search filter — the "search by words" input
 * with per-field scoping (file/name/comment/author/cat-title/
 * cat-desc/tags) and AND/OR mode.
 */
final readonly class AllwordsFilter
{
    /**
     * @param list<string>         $words   words submitted by the user (space-split client-side)
     * @param list<AllwordsField>  $fields  whitelist of fields to match against
     */
    public function __construct(
        public array $words,
        public array $fields,
        public AllwordsMode $mode,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    public static function fromArray(array $raw): ?self
    {
        $wordsRaw  = $raw['words'] ?? null;
        $fieldsRaw = $raw['fields'] ?? null;
        $words = self::normalizeWords($wordsRaw);
        if ($words === []) {
            return null;
        }
        $fields = [];
        if (is_array($fieldsRaw)) {
            foreach ($fieldsRaw as $v) {
                if (!is_string($v)) {
                    continue;
                }
                $case = AllwordsField::tryFrom($v);
                if ($case !== null) {
                    $fields[] = $case;
                }
            }
        }
        return new self($words, $fields, AllwordsMode::tryParse($raw['mode'] ?? null));
    }

    /**
     * Saved searches sometimes store `words` as a plain space-separated
     * string (legacy gallery search form) and sometimes as an already-
     * split list. Normalize both shapes to `list<string>`.
     *
     * @return list<string>
     */
    private static function normalizeWords(mixed $raw): array
    {
        if (is_string($raw)) {
            $parts = preg_split('/\s+/', trim($raw));
            return $parts === false ? [] : array_values(array_filter($parts, static fn (string $w): bool => $w !== ''));
        }
        if (is_array($raw)) {
            return array_values(array_filter(
                array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw),
                static fn (string $w): bool => $w !== '',
            ));
        }
        return [];
    }
}
