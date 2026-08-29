<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * One entry in the maintenance page's "Advanced features" group (P58-A).
 *
 * The list is empty in-tree -- {@see
 * \Piwigo\Admin\MaintenanceActionsPageRenderer} dispatches {@see
 * \Piwigo\Admin\Event\GetAdminAdvancedFeaturesLinks} with `[]` and no
 * in-tree handler adds to it -- so this shape comes from what the legacy
 * `get_admin_advanced_features_links` handlers actually push.
 *
 * `$icon` is nullable because they do not set it: both real handlers in
 * the reference plugin set carry `CAPTION` and `URL` only, while
 * `maintenance_actions.latte` read `$feature['ICON']` unguarded. Off an
 * untyped array that was a warning and an empty class prefix; as a
 * nullable property it is a stated absence that renders the same ''.
 */
final readonly class AdvancedFeatureLink
{
    public function __construct(
        public string $caption,
        public string $url,
        public ?string $icon = null,
    ) {}
}
