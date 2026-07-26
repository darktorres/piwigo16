<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for CatOptionsPageRenderer::render()
 * (page slug "cat_options") -- P27/SEC-40 Request DTO.
 *
 * `cat_true`/`cat_false`/`section`'s own `InputValidator::validate()`
 * calls only run when `$_POST` is non-empty, matching the original
 * exactly. Once `$_POST` is non-empty, `validate()`'s own array-mode
 * check already fatal-errors on any non-digit `cat_true`/`cat_false`
 * item, so the filtered `catTrue`/`catFalse` lists are always exactly
 * as long as the raw POST arrays -- there is no reachable case where the
 * original's separate `isset(...) and is_array(...) and count(...) > 0`
 * gate on the raw array diverges from checking the filtered list's own
 * emptiness, so `isFalsify`/`isTrueify` don't need a second raw-shape
 * flag.
 *
 * `sectionRaw` (used to call `setCategoryOption()`, unrestricted beyond
 * `section`'s own pattern validation) and `section` (restricted to the 4
 * known section names, defaulting to `'status'`, used for display/query
 * selection) are exposed separately -- the original computed two
 * genuinely different values from the same `$_GET['section']`, not one
 * value reused twice.
 */
final readonly class CatOptionsRequest
{
    /**
     * @param list<int> $catTrue
     * @param list<int> $catFalse
     */
    private function __construct(
        public bool $isSubmitted,
        public array $catTrue,
        public array $catFalse,
        public bool $isFalsify,
        public bool $isTrueify,
        public string $sectionRaw,
        public string $section,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post): self
    {
        $is_submitted = $post !== [];
        if ($is_submitted) {
            new InputValidator()
                ->validate('cat_true', $post, true, ValidationPattern::ID);
            new InputValidator()
                ->validate('cat_false', $post, true, ValidationPattern::ID);
            new InputValidator()
                ->validate('section', $get, false, '/^[a-z0-9_-]+$/i');
        }

        $post_cat_true = $post['cat_true'] ?? null;
        $cat_true = [];
        if (is_array($post_cat_true)) {
            foreach ($post_cat_true as $raw_cat_id) {
                if (is_numeric($raw_cat_id)) {
                    $cat_true[] = (int) $raw_cat_id;
                }
            }
        }

        $post_cat_false = $post['cat_false'] ?? null;
        $cat_false = [];
        if (is_array($post_cat_false)) {
            foreach ($post_cat_false as $raw_cat_id) {
                if (is_numeric($raw_cat_id)) {
                    $cat_false[] = (int) $raw_cat_id;
                }
            }
        }

        $section_param = $get['section'] ?? '';
        $section_raw = is_string($section_param) ? $section_param : '';

        $section = $get['section'] ?? 'status';
        $section = (is_string($section) and in_array($section, ['comments', 'visible', 'status', 'representative'], true))
            ? $section
            : 'status';

        return new self(
            $is_submitted,
            $cat_true,
            $cat_false,
            isset($post['falsify']),
            isset($post['trueify']),
            $section_raw,
            $section,
        );
    }
}
