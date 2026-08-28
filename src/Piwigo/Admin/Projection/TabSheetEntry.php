<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One entry of {@see \Piwigo\Admin\Tabsheet::$sheets}.
 */
final readonly class TabSheetEntry
{
    public function __construct(
        public string $caption,
        public string $url,
    ) {}
}
