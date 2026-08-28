<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Config\FilterViewDefinition;

/**
 * The `'search'` tab's own `$search` container, built by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * `'search'` case and read by `configuration_search.latte` directly
 * (P58-A). `$filtersViews` stays a map rather than becoming a VO of its
 * own: it is keyed by the site's admin-configurable filter-view names
 * (`CurrentConfig::$defaultFiltersViews`/`FilterViewsSelection::$filters`),
 * a genuinely dynamic set the template indexes by a runtime `$filter_name`.
 * Its *values* carry the type.
 */
final readonly class ConfigurationSearchTabData
{
    /**
     * @param array<string, FilterViewDefinition> $filtersViews
     * @param list<string> $filtersNames
     */
    public function __construct(
        public array $filtersViews,
        public bool $lastFiltersConf,
        public array $filtersNames,
    ) {}
}
