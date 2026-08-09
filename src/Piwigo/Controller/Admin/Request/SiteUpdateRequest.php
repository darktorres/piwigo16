<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for SiteUpdateSubController::handle()
 * (page slug "site_update").
 *
 * `post` retains the raw `$_POST` array -- the "Synchronize" form posts
 * ~15 individually-named fields (`sync`/`cat`/`subcats-included`/
 * `privacy_level`/`sync_meta`/`add_to_caddie`/`display_info`/`meta_all`/
 * `meta_empty_overrides`/`simulate`/`confirm`) each read with its own
 * distinct narrowing (`cat` alone has 3 different derived forms across
 * its call sites: a raw numeric-string spliced into SQL, an int-cast
 * array key, and an is_string-fallback used unconditionally elsewhere),
 * so no fixed property set covers all of them -- same "expose the raw
 * bag" shape as `ConfigurationRequest`/`BatchManagerGlobalRequest`.
 *
 * The original's `quick_sync` GET shortcut used to write 8 `$_POST` keys
 * in place to simulate a full form submission, so every later
 * `isset($_POST[...])`/comparison read in the same `handle()` call saw
 * the synthesized values -- `handle()` now builds a local working copy
 * of `post` and mutates that instead of the superglobal.
 *
 * `siteRaw` stays a raw, unvalidated string: the original's own
 * `is_numeric($_GET['site'])` check calls
 * `HtmlRenderingInterface::fatalError()` directly on failure (not
 * `InputValidator::validate()`), a presentation-layer call this DTO
 * has no business making -- the numeric check and its fatal path both
 * stay at the call site, just reading from this field instead of
 * `$_GET['site']` directly.
 */
final readonly class SiteUpdateRequest
{
    /**
     * @param array<int|string, mixed> $post
     */
    private function __construct(
        public ?string $siteRaw,
        public bool $quickSyncRequested,
        public ?string $catId,
        public array $post,
    ) {}

    public static function fromGlobals(InputValidator $inputValidator): self
    {
        return self::fromArrays($_GET, $_POST, $inputValidator);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post, InputValidator $inputValidator): self
    {
        $site_raw = $get['site'] ?? null;
        $site = is_string($site_raw) ? $site_raw : null;

        $cat_id_raw = $get['cat_id'] ?? null;
        $cat_id = null;
        if (isset($get['cat_id'])) {
            $inputValidator
                ->validate('cat_id', $get, false, ValidationPattern::ID);
            $cat_id = is_string($cat_id_raw) ? $cat_id_raw : null;
        }

        return new self(
            $site,
            isset($get['quick_sync']),
            $cat_id,
            $post,
        );
    }
}
