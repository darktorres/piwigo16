<?php

declare(strict_types=1);

namespace Piwigo\Event\Admin;

/**
 * Typed event for legacy `get_admin_advanced_features_links` (dispatch).
 *
 * Dispatched from: src/Piwigo/Controller/Admin/MaintenanceController.php
 */
final readonly class GetAdminAdvancedFeaturesLinks
{
    /**
     * @param array<mixed> $advancedFeatures
     */
    public function __construct(
        public array $advancedFeatures,
    ) {
    }

    /**
     * @param array<mixed> $advancedFeatures
     */
    public function withAdvancedFeatures(array $advancedFeatures): self
    {
        return new self($advancedFeatures);
    }
}
