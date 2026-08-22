<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * `$search['fields']['cat']`. `$words` stays `int|string` per element --
 * {@see \Piwigo\Controller\Api\Images\
 * ImageFilteredSearchCreateController} only regex-validates each id is
 * all-digits, it never casts, so a JSON payload can genuinely hand this
 * either a native int or a numeric string. Mutable, same rationale as
 * {@see AllwordsRule}.
 */
final class CategoryRule
{
    /**
     * @param list<int|string> $words
     */
    public function __construct(
        public array $words = [],
        public bool $subInc = false,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $words = $row['words'] ?? null;

        return new self(
            words: is_array($words) ? array_values(array_filter($words, static fn (mixed $v): bool => is_int($v) || is_string($v))) : [],
            subInc: (bool) ($row['sub_inc'] ?? false),
        );
    }

    /**
     * @return array{words: list<int|string>, sub_inc: bool}
     */
    public function toArray(): array
    {
        return [
            'words' => $this->words,
            'sub_inc' => $this->subInc,
        ];
    }
}
