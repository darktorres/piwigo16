<?php

declare(strict_types=1);

namespace Piwigo\Admin\Event;

/**
 * Typed event for the legacy `get_admin_advanced_features_links` filter.
 * No handler is registered for it anywhere today. No context -- every
 * real call site passes only the features list. Co-located here from `Piwigo\Event\Admin\GetAdminAdvancedFeaturesLinks` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class GetAdminAdvancedFeaturesLinks
{
    /**
     * @param array<mixed> $advancedFeatures
     */
    public function __construct(
        public array $advancedFeatures,
    ) {}
}
