<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated request shape for GroupListPageRenderer::render() (page slug
 * "group_list") -- P26/SEC-40 Request DTO. No values are ever read out of
 * `$_POST`/`$_GET` here (confirmed via GroupListSubController's own
 * docblock: `delete`/`toggle_is_default` are dead validation, nothing in
 * this page acts on them -- group create/delete/rename all go through the
 * WS API instead), so there's nothing to pattern-validate with
 * `InputValidator`; this DTO only names the one real decision the raw
 * superglobals were being read for -- whether a CSRF token must be
 * checked before rendering.
 */
final readonly class GroupListActionRequest
{
    private function __construct(
        public bool $requiresCsrfCheck,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_POST, $_GET);
    }

    /**
     * @param array<array-key, mixed> $post
     * @param array<array-key, mixed> $get
     */
    public static function fromArrays(array $post, array $get): self
    {
        return new self($post !== [] || isset($get['delete']) || isset($get['toggle_is_default']));
    }
}
