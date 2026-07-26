<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for PermalinksSubController::handle()
 * (page slug "permalinks") -- P27/SEC-40 Request DTO. `catId`'s own
 * `InputValidator::validate()` call runs unconditionally, matching the
 * original exactly (it validated the format whenever `cat_id` was
 * present, regardless of the other fields). `psfPresent`/`psf` and
 * `dpsfPresent`/`dpsf` split each sort-column GET param (read by
 * `parseSortVariables()`, called once per param name) into a presence
 * flag plus a normalized string -- the original's `isset($_GET[...])`
 * presence check and its `@$_GET[...]` equality-comparison read depend
 * on each other in ways a single nullable string can't reproduce for a
 * present-but-non-string value.
 */
final readonly class PermalinksRequest
{
    private function __construct(
        public int $catId,
        public bool $isSetPermalink,
        public string $permalink,
        public bool $isSave,
        public bool $deletePermanentPresent,
        public string $deletePermanent,
        public bool $psfPresent,
        public ?string $psf,
        public bool $dpsfPresent,
        public ?string $dpsf,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST);
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    public static function fromArrays(array $get, array $post): self
    {
        new InputValidator()->validate('cat_id', $post, false, ValidationPattern::ID);

        $post_cat_id = isset($post['cat_id']) && is_numeric($post['cat_id']) ? (int) $post['cat_id'] : 0;

        $permalink = $post['permalink'] ?? null;
        $permalink = is_string($permalink) ? $permalink : '';

        $delete_permanent = $get['delete_permanent'] ?? null;

        $psf = $get['psf'] ?? null;
        $dpsf = $get['dpsf'] ?? null;

        return new self(
            $post_cat_id,
            isset($post['set_permalink']),
            $permalink,
            isset($post['save']),
            isset($get['delete_permanent']),
            is_string($delete_permanent) ? $delete_permanent : '',
            isset($get['psf']),
            is_string($psf) ? $psf : null,
            isset($get['dpsf']),
            is_string($dpsf) ? $dpsf : null,
        );
    }
}
