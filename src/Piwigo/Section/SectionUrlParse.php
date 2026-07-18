<?php

declare(strict_types=1);

namespace Piwigo\Section;

/**
 * Parsed section URL state -- the structured result of tokenizing
 * "category/12-name/start-24"-style URLs, before the $page/$template
 * population pipeline in SectionPopulator::populate() runs on top of
 * it. Renamed from SectionContext (Legacy Coupling Retirement Track A
 * batch A5.2e): SectionContext is now the full gallery-navigation-
 * context value object SectionPopulator builds and registers via
 * SectionContextRegistry -- this narrower, parse-step-only shape needed
 * its own name once that split became real instead of aspirational.
 */
final readonly class SectionUrlParse
{
    /**
     * @param list<string> $tokens
     * @param array<string, mixed> $parsed the parse_section_url()
     *   (functions_url.inc.php) result -- kept as a generic array since
     *   that function's own return type is already array<string, mixed>,
     *   no tighter VO exists for its shape yet.
     */
    public function __construct(
        public string $rootPath,
        public string $sectionUrl,
        public array $tokens,
        public int $nextToken,
        public int|string|null $imageId,
        public ?string $imageFile,
        public array $parsed,
    ) {}
}
