<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

use Piwigo\Core\ValidationPattern;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for GroupPermPageRenderer::render()
 * (page slug "group_perm") -- P26/SEC-40 Request DTO. `cat_true`/
 * `cat_false`'s own `InputValidator::validate()` calls only run when
 * `$_POST` is non-empty, matching the original exactly. `groupIdPresent`/
 * `groupId` stay split -- the original's own presence check and its
 * separate `is_numeric(...)` re-check both hard-reject via
 * `HtmlRenderingInterface::fatalError()` (a direct side effect), so both
 * stay at the call site, same precedent as UserPermSubmitRequest.
 */
final readonly class GroupPermSubmitRequest
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
        public bool $groupIdPresent,
        public mixed $groupId,
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
        $isSubmitted = $post !== [];
        if ($isSubmitted) {
            InputValidator::createStatic()
                ->validate('cat_true', $post, true, ValidationPattern::ID);
            InputValidator::createStatic()
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

        InputValidator::createStatic()
            ->validate('group_id', $get, false, ValidationPattern::ID);

        return new self(
            $isSubmitted,
            $cat_true,
            $cat_false,
            isset($post['falsify']),
            isset($post['trueify']),
            isset($get['group_id']),
            $get['group_id'] ?? null,
        );
    }
}
