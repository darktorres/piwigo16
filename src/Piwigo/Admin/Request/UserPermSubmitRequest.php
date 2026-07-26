<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for UserPermPageRenderer::render()
 * (page slug "user_perm") -- P27/SEC-40 Request DTO. `cat_true`/
 * `cat_false`'s own `InputValidator::validate()` calls only run when
 * `$_POST` is non-empty, matching the original exactly. `userId` stays
 * `mixed` (raw `$_GET['user_id']`) -- its own `is_numeric(...) ?:
 * fatalError('user_id URL parameter is missing')` hard rejection stays at
 * the call site (a direct `HtmlRenderingInterface::fatalError()` side
 * effect), same precedent as SearchQueryRequest/UserActivityRequest.
 */
final readonly class UserPermSubmitRequest
{
    /**
     * @param list<string> $catTrue
     * @param list<int> $catFalse
     */
    private function __construct(
        public bool $isSubmitted,
        public array $catTrue,
        public array $catFalse,
        public bool $isFalsify,
        public bool $isTrueify,
        public mixed $userId,
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
        $isSubmitted = $post !== [];
        if ($isSubmitted) {
            new InputValidator()->validate('cat_true', $post, true, ValidationPattern::ID);
            new InputValidator()->validate('cat_false', $post, true, ValidationPattern::ID);
        }

        $post_cat_true = $post['cat_true'] ?? null;
        $cat_true = [];
        if (is_array($post_cat_true)) {
            foreach ($post_cat_true as $raw_cat_id) {
                if (is_string($raw_cat_id)) {
                    $cat_true[] = $raw_cat_id;
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

        return new self(
            $isSubmitted,
            $cat_true,
            $cat_false,
            isset($post['falsify']),
            isset($post['trueify']),
            $get['user_id'] ?? null,
        );
    }
}
