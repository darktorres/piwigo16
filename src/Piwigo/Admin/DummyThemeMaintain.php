<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin;

/**
 * used when a theme uses the old procedural declaration of maintenance methods
 */
class DummyThemeMaintain extends ThemeMaintain
{
    // Each is_callable() here checks for a bare function dynamically defined
    // by a theme's own maintain.inc.php (include_once'd in
    // themes::build_maintain_class(), outside this codebase, not statically
    // knowable) — genuinely undecidable until real ThemeMaintain contracts
    // (P31) replace this pre-2.7 procedural fallback entirely.
    /**
     * @param array<int, string> $errors - not natively typed: ThemeMaintain's
     *   own base declares $errors with no native type, and PHP's parameter
     *   contravariance rules fatal on narrowing an untyped parent param to a
     *   native type in the override (verified empirically)
     */
    #[\Override]
    public function activate($theme_version, &$errors = []): mixed
    {
        // @phpstan-ignore function.impossibleType
        if (is_callable('theme_activate')) {
            // @phpstan-ignore function.notFound
            return theme_activate($this->theme_id, $theme_version, $errors);
        }

        return null;
    }

    #[\Override]
    public function deactivate(): mixed
    {
        // @phpstan-ignore function.impossibleType
        if (is_callable('theme_deactivate')) {
            // @phpstan-ignore function.notFound
            return theme_deactivate($this->theme_id);
        }

        return null;
    }

    #[\Override]
    public function delete(): mixed
    {
        // @phpstan-ignore function.impossibleType
        if (is_callable('theme_delete')) {
            // @phpstan-ignore function.notFound
            return theme_delete($this->theme_id);
        }

        return null;
    }
}
