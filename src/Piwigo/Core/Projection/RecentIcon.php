<?php

declare(strict_types=1);

namespace Piwigo\Core\Projection;

/**
 * {@see \Piwigo\Core\RecentIconResolver::getIcon()}'s result -- the "posted
 * recently" marker three templates render next to an album or a photo
 * (`mainpage_categories`, `thumbnails`, `menubar_categories`), each from a
 * different producer.
 *
 * Replaces a `false|array{}|array{TITLE: string, IS_CHILD_DATE: bool}`
 * return whose three states all meant one of two things: `false` for "no
 * date to judge" and `[]` for "not recent" both rendered nothing, and only
 * the populated array rendered the icon. Every call site asked exactly that
 * question, as `!empty($x['icon_ts'])`. `null` now says it once.
 */
final readonly class RecentIcon
{
    public function __construct(
        public string $title,
        public bool $isChildDate,
    ) {}
}
