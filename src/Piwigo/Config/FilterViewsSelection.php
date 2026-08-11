<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * Admin-customized search-filter selection: per-filter access/default
 * overrides plus the sibling "last filters configuration" flag bundled
 * into the same DB row (see ConfigurationSubController's own save
 * handler for where the two get combined).
 */
final readonly class FilterViewsSelection
{
    /**
     * @param array<string, FilterViewDefinition> $filters
     */
    public function __construct(
        public array $filters,
        public bool $lastFiltersConf,
    ) {}

    /**
     * @param array<mixed> $value
     */
    public static function fromArray(array $value): self
    {
        $filters = [];
        foreach ($value as $key => $entry) {
            if (! is_string($key) || $key === 'last_filters_conf' || ! is_array($entry)) {
                continue;
            }
            $definition = FilterViewDefinition::tryFromArray($entry);
            if ($definition instanceof FilterViewDefinition) {
                $filters[$key] = $definition;
            }
        }

        $lastFiltersConf = $value['last_filters_conf'] ?? null;

        return new self(
            filters: $filters,
            lastFiltersConf: is_bool($lastFiltersConf) ? $lastFiltersConf : false,
        );
    }
}
