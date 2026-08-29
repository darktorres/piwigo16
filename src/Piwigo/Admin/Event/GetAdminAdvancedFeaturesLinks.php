<?php

declare(strict_types=1);

namespace Piwigo\Admin\Event;

use Piwigo\Admin\Projection\AdvancedFeatureLink;

/**
 * Typed event for the legacy `get_admin_advanced_features_links` filter.
 * No handler is registered for it anywhere today. No context -- every
 * real call site passes only the features list.
 */
final class GetAdminAdvancedFeaturesLinks
{
    /**
     * @param list<AdvancedFeatureLink> $advancedFeatures
     */
    public function __construct(
        public array $advancedFeatures,
    ) {}
}
