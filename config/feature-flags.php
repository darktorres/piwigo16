<?php

declare(strict_types=1);

// Feature flags, checked via Piwigo\Core\FeatureFlag::isEnabled(). Read-only
// for now -- SEC-58's authz-gated toggling needs CurrentUser (P16) + a real
// admin UI (P21) + audit_log (P13-16). Empty until a real flag is needed;
// grows one entry at a time, same discipline as config/container.php and
// config/routes.php.

/**
 * @return array<string, bool>
 */
return [];
