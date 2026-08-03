<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET['theme']` for ThemeSubController::handle() (page slug
 * "theme") -- P26/SEC-40 Request DTO. Charset pattern matches
 * `ExtensionScanner`'s own directory-scan filter (`/^[a-zA-Z0-9-_]+$/`,
 * see its `scan()` method) -- every real theme id `ThemeSubController`
 * later checks this value against via `in_array($theme,
 * array_keys($fs_themes), true)` is already constrained to that same
 * charset by the scan itself, so this is real defense-in-depth (matching
 * this project's existing precedent for admin-only inputs, e.g.
 * AlbumSubController's tab allowlist), not a fix for an otherwise-reachable
 * hole.
 *
 * Deliberate, in-scope behavior change: rejection now goes through
 * `InputValidator`'s standard "[Hacking attempt] ..." wording instead of
 * the original bespoke "Invalid theme URL" message -- no existing test
 * asserts on the old wording (confirmed via grep).
 */
final readonly class ThemeIdRequest
{
    private function __construct(
        public string $themeId,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        $theme_raw = $source['theme'] ?? null;
        $theme = is_string($theme_raw) ? $theme_raw : '';

        InputValidator::createStatic()
            ->validate('theme', [
                'theme' => $theme,
            ], false, '/^[a-zA-Z0-9-_]+$/', true);

        return new self($theme);
    }
}
