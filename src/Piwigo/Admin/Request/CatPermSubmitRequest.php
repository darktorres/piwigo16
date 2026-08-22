<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_POST` shape for CatPermPageRenderer::render()'s permissions
 * save form (the "permissions" tab of the "album" page slug).
 * `status` is allowlisted against `'public'`/`'private'` (P44-H) --
 * anything else, including a genuinely-a-string-but-unrecognized value
 * (a plain type check alone lets that straight through), falls back to
 * an empty string, matching `CategoryAdminService::setCategoryPermissions()`'s
 * own two-literal branching so a stray value can never reach
 * `category.status` as-is; `groups`/`users` are the real int ids only,
 * matching the original's own defensive per-element `is_numeric()` cast.
 */
final readonly class CatPermSubmitRequest
{
    /**
     * @param list<int> $groups
     * @param list<int> $users
     */
    private function __construct(
        public bool $isSubmitted,
        public string $status,
        public bool $applyOnSub,
        public array $groups,
        public array $users,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_POST);
    }

    /**
     * @param array<array-key, mixed> $post
     */
    public static function fromArray(array $post): self
    {
        $status_raw = $post['status'] ?? null;
        $status = in_array($status_raw, ['public', 'private'], true) ? $status_raw : '';

        return new self(
            $post !== [],
            $status,
            isset($post['apply_on_sub']),
            self::intListFrom($post, 'groups'),
            self::intListFrom($post, 'users'),
        );
    }

    /**
     * @param array<array-key, mixed> $post
     * @return list<int>
     */
    private static function intListFrom(array $post, string $key): array
    {
        $raw = $post[$key] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $raw_id) {
            if (is_numeric($raw_id)) {
                $ids[] = (int) $raw_id;
            }
        }

        return $ids;
    }
}
