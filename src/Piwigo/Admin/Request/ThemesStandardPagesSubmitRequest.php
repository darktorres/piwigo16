<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_POST`/`$_FILES` shape for
 * ThemesStandardPagesPageRenderer::render()'s save form (page slug
 * "themes_standard_pages") -- P27/SEC-40 Request DTO. `selectedLogo`/
 * `selectedSkin` are checked against the renderer's own fixed option
 * lists (passed in by the caller, same as ExtensionTabRequest's pattern
 * parameter) -- an unrecognized value is simply not applied, matching
 * the original's `isset() and in_array(..., true)` guard exactly.
 * `logoTmpName`/`logoName` mirror the original `$_FILES['std_pgs_logo']`
 * shape check (a real, non-empty temp path required before any upload
 * handling runs).
 */
final readonly class ThemesStandardPagesSubmitRequest
{
    private function __construct(
        public bool $isSubmitted,
        public bool $useStandardPages,
        public ?string $selectedLogo,
        public ?string $selectedSkin,
        public ?string $logoTmpName,
        public string $logoName,
    ) {}

    /**
     * @param list<string> $logoOptions
     * @param list<string> $skinOptions
     */
    public static function fromGlobals(array $logoOptions, array $skinOptions): self
    {
        return self::fromArrays($_POST, $_FILES, $logoOptions, $skinOptions);
    }

    /**
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $files
     * @param list<string> $logoOptions
     * @param list<string> $skinOptions
     */
    public static function fromArrays(array $post, array $files, array $logoOptions, array $skinOptions): self
    {
        $isSubmitted = isset($post['submit']);

        // use_standard_pages or not -- a checkbox POST value, so "not
        // set"/''/'0' are all "unchecked", matching empty()'s own
        // semantics for this input without using empty() itself.
        $use_standard_pages_raw = $post['use_standard_pages'] ?? null;
        $useStandardPages = $use_standard_pages_raw !== null
            && $use_standard_pages_raw !== ''
            && $use_standard_pages_raw !== '0';

        $logo_raw = $post['std_pgs_display_logo'] ?? null;
        $selectedLogo = (is_string($logo_raw) && in_array($logo_raw, $logoOptions, true)) ? $logo_raw : null;

        $skin_raw = $post['std_pgs_selected_skin'] ?? null;
        $selectedSkin = (is_string($skin_raw) && in_array($skin_raw, $skinOptions, true)) ? $skin_raw : null;

        $std_pgs_logo_upload = $files['std_pgs_logo'] ?? null;
        $logoTmpName = null;
        $logoName = '';
        if (
            is_array($std_pgs_logo_upload)
            and isset($std_pgs_logo_upload['tmp_name'])
            and is_string($std_pgs_logo_upload['tmp_name'])
            and $std_pgs_logo_upload['tmp_name'] !== ''
        ) {
            $logoTmpName = $std_pgs_logo_upload['tmp_name'];
            $logoName = isset($std_pgs_logo_upload['name']) && is_string($std_pgs_logo_upload['name'])
                ? $std_pgs_logo_upload['name']
                : '';
        }

        return new self($isSubmitted, $useStandardPages, $selectedLogo, $selectedSkin, $logoTmpName, $logoName);
    }
}
