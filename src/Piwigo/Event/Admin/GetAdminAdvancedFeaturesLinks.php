<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for the legacy `get_admin_advanced_features_links` filter.
 * No handler is registered for it anywhere today.
 */
final readonly class GetAdminAdvancedFeaturesLinks
{
    /**
     * @param array<mixed> $advancedFeatures
     */
    public function __construct(
        public array $advancedFeatures,
    ) {}
}
