<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

/**
 * The `'search'` tab's own `$search` container, built by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * `'search'` case. Its top-level shape is fixed (always exactly these 3
 * keys), but `$filtersViews`/`$filtersNames` stay array-shaped -- both
 * are keyed by the site's own admin-configurable filter-view names
 * (`CurrentConfig::$defaultFiltersViews`/`FilterViewsSelection::$filters`),
 * a genuinely dynamic set, not a fixed enumerable one like this tab's
 * own checkbox fields elsewhere in this campaign.
 * `configuration_search.latte` still reads these via `$search['key']`
 * (through {@see ConfigurationSearchView}'s own array-typed `$search`),
 * so `toArray()` reproduces that exact shape.
 *
 * `configuration_search.latte:87` reads
 * `$search['filters_views']['last_filters_conf']` (a nested key), but
 * `last_filters_conf` has only ever been a SIBLING of `filters_views`,
 * never nested inside it, in either the old array or this VO -- a
 * pre-existing template bug faithfully preserved here (this
 * `toArray()` reproduces the exact original, buggy structure), not
 * fixed: out of scope for this type-only refactor.
 */
final readonly class ConfigurationSearchTabData
{
    /**
     * @param array<string, array<string, mixed>> $filtersViews
     * @param list<string> $filtersNames
     */
    public function __construct(
        public array $filtersViews,
        public bool $lastFiltersConf,
        public array $filtersNames,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filters_views' => $this->filtersViews,
            'last_filters_conf' => $this->lastFiltersConf,
            'filters_names' => $this->filtersNames,
        ];
    }
}
