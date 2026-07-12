<?php

declare(strict_types=1);

namespace Piwigo\Section;

/**
 * Parsed section URL state -- the structured result of tokenizing
 * "category/12-name/start-24"-style URLs, before the (still procedural,
 * P22 scope) $page/$template population pipeline in
 * include/section_init.inc.php runs on top of it.
 */
final readonly class SectionContext
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
