<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for UserPermPageRenderer::render()
 * (page slug "user_perm"). `cat_true`/
 * `cat_false`'s own `InputValidator::validate()` calls only run when
 * `$_POST` is non-empty, matching the original exactly. `user_id`'s own
 * `InputValidator::validate()` call fatal-errors on any malformed value
 * before construction, same "presence + digit ID" shape as
 * `GroupPermSubmitRequest`'s `group_id`.
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
        public ?UserId $userId,
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
        $isSubmitted = $post !== [];
        if ($isSubmitted) {
            $inputValidator
                ->validate('cat_true', $post, true, ValidationPattern::ID);
            $inputValidator
                ->validate('cat_false', $post, true, ValidationPattern::ID);
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

        $inputValidator
            ->validate('user_id', $get, false, ValidationPattern::ID);

        return new self(
            $isSubmitted,
            $cat_true,
            $cat_false,
            isset($post['falsify']),
            isset($post['trueify']),
            UserId::tryFrom($get['user_id'] ?? null),
        );
    }
}
