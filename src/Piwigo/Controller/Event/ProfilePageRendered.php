<?php

declare(strict_types=1);

namespace Piwigo\Controller\Event;

/**
 * Typed marker event for the legacy `loc_end_profile` notification. No
 * payload, no handler registered anywhere today.
 */
final readonly class ProfilePageRendered {}
