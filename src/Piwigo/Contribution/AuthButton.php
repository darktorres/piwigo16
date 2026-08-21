<?php

declare(strict_types=1);

namespace Piwigo\Contribution;

/**
 * A third-party sign-in button (Google, Facebook, ...) a plugin
 * contributes to the identification and registration pages -- the
 * typed replacement for a hand-written
 * `set_prefilter('identification', ...)`/`set_prefilter('register', ...)`
 * patch, which real plugins (`oAuth`, `SocialConnect`) hand-write
 * against the raw HTML, inserting right after the login/register
 * form's own closing `</form>`.
 *
 * Unlike `ButtonContribution`, this never navigates via a plain URL --
 * a real sign-in flow is JS-driven (redirect to the provider, then
 * back). `$providerId` is a stable machine identifier the page's own
 * JS dispatches on (rendered as a `data-provider` attribute), not a
 * link target. One shared list for both pages, not two independent
 * ones like `ProfileField`'s own split -- every real plugin read
 * registers the identical provider list on both `identification` and
 * `register` with the same callback, so there's no real case of a
 * provider button wanted on one page but not the other.
 */
final readonly class AuthButton
{
    public function __construct(
        public string $label,
        public string $providerId,
        public ?string $icon = null,
        public int $order = 50,
    ) {}
}
