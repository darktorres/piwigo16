<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * Hides one of the profile-edit form's own native password fields --
 * the typed replacement for a hand-written
 * `set_prefilter('profile_content', ...)` patch a real plugin
 * (`oAuth`) uses to hide password fields once it becomes the only
 * authentication method: the original hides them client-side via jQuery
 * `.hide()`; this hides them server-side, in the rendered markup
 * itself. Deliberately narrow -- scoped to exactly the one real,
 * concrete need found (no generic "hide any named field on any form"
 * mechanism invented on spec).
 */
enum FieldOverride
{
    case Password;
}
