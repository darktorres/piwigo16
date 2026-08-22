<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * `$search['fields']['tags']`. `$words` stays `int|string` per element,
 * same reasoning as {@see CategoryRule::$words}. Mutable, same
 * rationale as {@see AllwordsRule}.
 */
final class TagsRule
{
    /**
     * @param list<int|string> $words
     * @param 'AND'|'OR' $mode
     */
    public function __construct(
        public array $words = [],
        public string $mode = 'AND',
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $words = $row['words'] ?? null;
        $mode = $row['mode'] ?? null;

        return new self(
            words: is_array($words) ? array_values(array_filter($words, static fn (mixed $v): bool => is_int($v) || is_string($v))) : [],
            mode: $mode === 'OR' ? 'OR' : 'AND',
        );
    }

    /**
     * @return array{words: list<int|string>, mode: string}
     */
    public function toArray(): array
    {
        return [
            'words' => $this->words,
            'mode' => $this->mode,
        ];
    }
}
