<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * One link inside an `ActionContribution`'s own expandable panel -- e.g.
 * one flag in a language-switcher's flag list, or one tag in core's own
 * "Related tags" dropdown. Deliberately narrower than `ButtonContribution`
 * (no `$id`/`$order`: panel links are a plain, unranked list, not
 * independently addressable or reorderable against each other).
 */
final readonly class PanelLink
{
    public function __construct(
        public string $label,
        public string $url,
        public ?string $icon = null,
    ) {}
}
