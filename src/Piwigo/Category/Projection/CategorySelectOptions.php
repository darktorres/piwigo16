<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * The `{html_options}`-ready shape built by
 * {@see \Piwigo\Category\CategoryService::displaySelectCategories()} and
 * its `sortAndDisplaySelectCategories()`/10-wrapper-method callers.
 * Formerly assigned directly to the template under a caller-chosen
 * `$blockname` key (a real `Template::assign($blockname, ...)` /
 * `Template::assign($blockname . '_selected', ...)` pair) -- every real
 * caller's own blockname is a compile-time-known literal (confirmed via
 * a full-repo grep of every real call site), so callers now receive this
 * value object instead and assign it themselves under their own literal
 * key, same as any other page data.
 */
final readonly class CategorySelectOptions
{
    /**
     * @param array<int|string, string> $options
     * @param array<int, mixed> $selected
     */
    public function __construct(
        public array $options,
        public array $selected,
    ) {}
}
