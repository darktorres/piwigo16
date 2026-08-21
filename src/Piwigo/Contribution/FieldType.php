<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * The `<input>` shape a `ProfileField` renders as -- the two forms seen
 * across every real register/profile-field plugin read so far
 * (`~/piwigo16-plugins`'s `AddInfousers`, `CustomUsersFields`).
 */
enum FieldType
{
    case Text;
    case Checkbox;
}
