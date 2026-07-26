<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

/**
 * Validated `$_POST` shape for ProfileFormHandler::saveFromPost()/
 * loadIntoTemplate() -- P27/SEC-40 Request DTO.
 *
 * `post` retains the raw `$_POST` array. `saveFromPost()`'s own
 * "special user" (guest/default user) and "not in admin context"
 * branches used to `unset()`/overwrite several `$_POST` keys in place
 * (`username`/`mail_address`/`password`/`use_new_pwd`/`passwordConf`/
 * `theme`/`language`, plus `redirect` on a username conflict) so every
 * later read in that same method saw the overridden state -- all of
 * that stays entirely within one method call (unlike e.g.
 * `AdminShell::runDispatch()`'s alias rewriting, which must survive
 * into a later `RequestFactory::fromGlobals()` call), so
 * `saveFromPost()` now builds its own local working copy of `post` and
 * mutates that instead of the superglobal.
 */
final readonly class ProfileFormSubmitRequest
{
    /**
     * @param array<int|string, mixed> $post
     */
    private function __construct(
        public bool $isValidateSubmitted,
        public bool $isSubmitPresent,
        public array $post,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_POST);
    }

    /**
     * @param array<int|string, mixed> $post
     */
    public static function fromArray(array $post): self
    {
        return new self(
            isset($post['validate']),
            isset($post['submit']),
            $post,
        );
    }
}
