<?php

declare(strict_types=1);

namespace Piwigo\History;

/** One row from findPageByWhere() — a full history detail row for display in the admin history page. */
final readonly class HistoryPageRow
{
    public function __construct(
        public string  $date,
        public string  $time,
        public int     $userId,
        public string  $ip,
        public ?string $section,
        public ?int    $categoryId,
        public ?int    $searchId,
        public ?string $tagIds,
        public ?int    $imageId,
        public ?string $imageType,
    ) {
    }
}
