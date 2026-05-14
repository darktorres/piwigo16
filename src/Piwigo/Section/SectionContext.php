<?php

declare(strict_types=1);

namespace Piwigo\Section;

/**
 * Immutable value object holding the section/navigation context for the current
 * gallery request. Built once by SectionInitializer::initialize() and
 * distributed via SectionContextRegistry.
 */
final class SectionContext
{
    /**
     * @param list<string>        $items            Image IDs in display order
     * @param list<mixed>         $tags             Tag rows (each element is array<string,mixed>)
     * @param list<int>           $tagIds
     * @param list<string>        $list             Explicit image ID list (section=list)
     * @param array<mixed>|null   $category         Current category row
     * @param list<mixed>|null    $combinedCategories (each element is array<string,mixed>)
     * @param array<mixed>        $searchDetails
     * @param array<mixed>        $qsearchDetails
     * @param list<string>        $whereClauses
     * @param list<int|string>    $chronologyDate
     */
    public function __construct(
        public readonly string  $section            = 'categories',
        public readonly string  $sectionUrl         = '',
        public readonly string  $rootPath           = '',
        public readonly array   $items              = [],
        public readonly int     $start              = 0,
        public readonly int     $startcat           = 0,
        public readonly int     $nbImagePage        = 0,
        public readonly bool    $flat               = false,
        public readonly bool    $isHomepage         = false,
        public readonly bool    $superOrderBy       = false,
        public readonly ?string $imageId            = null,
        public readonly string  $imageFile          = '',
        public readonly ?array  $category           = null,
        public readonly ?array  $combinedCategories = null,
        public readonly array   $tags               = [],
        public readonly array   $tagIds             = [],
        public readonly array   $list               = [],
        public readonly ?string $search             = null,
        public readonly ?string $searchId           = null,
        public readonly array   $searchDetails      = [],
        public readonly array   $qsearchDetails     = [],
        public readonly array   $whereClauses       = [],
        public readonly bool    $useRegexpICU       = false,
        public readonly array   $chronologyDate     = [],
        public readonly string  $chronologyField    = '',
        public readonly string  $chronologyView     = '',
        public readonly string  $chronologyStyle    = '',
        public readonly string  $title              = '',
        public readonly string  $comment            = '',
        public readonly string  $sectionTitle       = '',
        public readonly string  $feed               = '',
        public readonly bool    $isExternal         = false,
    ) {
    }
}
