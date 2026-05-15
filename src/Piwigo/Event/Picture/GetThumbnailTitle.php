<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for legacy `get_thumbnail_title` (dispatch).
 *
 * Dispatched from: src/Piwigo/Html/HtmlService.php
 */
final readonly class GetThumbnailTitle
{
    /**
     * @param array<mixed> $info
     */
    public function __construct(
        public string $title,
        public array $info,
    ) {
    }

    public function withTitle(string $title): self
    {
        return new self($title, $this->info);
    }
}
