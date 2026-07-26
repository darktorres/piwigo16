<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST`/`$_FILES` shape for
 * ConfigurationSubController::handle()/processSizes()/processWatermark()
 * (page slug "configuration") -- P27/SEC-40 Request DTO.
 *
 * `post`/`files` retain the raw `$_POST`/`$_FILES` arrays -- this page's
 * per-tab submit handling (`main`/`comments`/`display`/`search`) mutates
 * several fields in place before a single generic config-row UPDATE loop
 * reads them back (the same "many fields feed one later loop, all within
 * the same call" shape as `ProfileFormSubmitRequest`/
 * `BatchManagerGlobalRequest`), and the "sizes"/"watermark" tabs' own
 * `processSizes()`/`processWatermark()` handlers each parse their own
 * differently-shaped nested POST fields (`d[type][...]`/`w[...]`) -- no
 * fixed property set covers either shape, so `handle()` keeps building
 * its own local `post` working copy from this bag exactly as it did from
 * `$_POST` before, and passes `post`/`files` through as plain parameters
 * to the 2 process methods (neither one needs to write back into
 * `handle()`'s own copy: both already exclude themselves from the
 * generic config-row UPDATE loop and persist through their own typed
 * `ImageStdParams`/`WatermarkParams` save calls instead).
 *
 * `restoreSettingsRequested` replaces the original's own
 * `isset($_GET['action']) and $_GET['action'] === 'restore_settings'`
 * check; the corresponding activity-log `config_action` value is always
 * the literal `'restore_settings'` string by the time that code runs
 * (gated by this same flag), so the call site no longer needs to re-read
 * `$_GET['action']` for it.
 */
final readonly class ConfigurationRequest
{
    /**
     * @param array<int|string, mixed> $post
     * @param array<int|string, mixed> $files
     */
    private function __construct(
        public string $section,
        public bool $restoreSettingsRequested,
        public bool $isSubmitted,
        public array $post,
        public array $files,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST, $_FILES);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     * @param array<int|string, mixed> $files
     */
    public static function fromArrays(array $get, array $post, array $files): self
    {
        new InputValidator()
            ->validate('section', $get, false, '/^[a-z]+$/i');

        $section_raw = $get['section'] ?? null;
        $section = is_string($section_raw) ? $section_raw : 'main';

        $action_raw = $get['action'] ?? null;
        $restore_settings_requested = isset($get['action']) && $action_raw === 'restore_settings';

        return new self(
            $section,
            $restore_settings_requested,
            isset($post['submit']),
            $post,
            $files,
        );
    }
}
