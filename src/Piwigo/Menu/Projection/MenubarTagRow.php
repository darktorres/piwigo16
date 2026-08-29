<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

/**
 * One tag in the menubar's tag cloud.
 *
 * `$level` is what makes it a cloud: `theme.css` sizes `.tagLevel1`
 * through `.tagLevel5` from 90% to 150%, and
 * {@see \Piwigo\Tag\TagService::addLevelToTags()} assigns it from each
 * tag's own counter against the average of the displayed set. No
 * `$counter` field -- it is what the level is computed from, and nothing
 * in this block renders it.
 */
final readonly class MenubarTagRow
{
    public function __construct(
        public string $url,
        public string $name,
        public int $level,
    ) {}
}
