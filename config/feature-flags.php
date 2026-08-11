<?php

declare(strict_types=1);

// Feature flags, checked via Piwigo\Core\FeatureFlag::isEnabled(). Read-only
// for now -- SEC-58's authz-gated toggling needs CurrentUser, a real admin
// UI, and audit_log wired together for it. Empty until a real flag is
// needed; grows one entry at a time, same discipline as
// config/container.php and config/routes.php.

/**
 * @return array<string, bool>
 */
return [];
