<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Tools;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * Throwaway fixture, not a real production class -- the shape real
 * contexts use when `toArray()` wraps one or more properties in a literal
 * (`CalendarChronologyPageContext`'s `chronology`,
 * `SectionFavoritePageContext`'s `favorite`), plus the two cases that must
 * still fall through to the first-property-reference approximation: a
 * literal with a non-string key, and one built by spreading.
 */
final readonly class ContextVariableExtractorTestArrayLiteralFixture implements TemplatePageContext
{
    /**
     * @param list<string> $spread
     */
    public function __construct(
        public string $title,
        public int $count,
        public array $spread,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'wrapped' => [
                'TITLE' => $this->title,
                'COUNT' => $this->count,
            ],
            'int_keyed' => [
                0 => $this->title,
            ],
            'spread_built' => [
                ...$this->spread,
            ],
        ];
    }
}
