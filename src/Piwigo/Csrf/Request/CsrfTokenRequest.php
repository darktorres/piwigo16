<?php

declare(strict_types=1);

namespace Piwigo\Csrf\Request;

/**
 * Validated `$_REQUEST['pwg_token']` shape for CsrfService::check() --
 * P27/SEC-40 Request DTO. `check()` has no parameters and is called from
 * dozens of controllers via checkOrFail(), so this is constructed inside
 * check() itself rather than threaded through every one of its callers.
 *
 * `submittedToken` collapses absent/non-string/empty-string into a
 * single `null`, matching the original's own `! is_string($submitted)
 * || $submitted === ''` combined gate exactly (both cases already meant
 * "no token was submitted", not 2 distinct conditions to preserve).
 */
final readonly class CsrfTokenRequest
{
    private function __construct(
        public ?string $submittedToken,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_REQUEST);
    }

    /**
     * @param array<int|string, mixed> $request
     */
    public static function fromArray(array $request): self
    {
        $submitted_raw = $request['pwg_token'] ?? null;
        $submitted = is_string($submitted_raw) && $submitted_raw !== '' ? $submitted_raw : null;

        return new self($submitted);
    }
}
