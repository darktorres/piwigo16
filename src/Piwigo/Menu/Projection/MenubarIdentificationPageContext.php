<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Menu\MenubarRenderer::render()}. Every field here is
 * genuinely optional -- the original code only ever assigns each of
 * these template keys under its own runtime condition, omitted here
 * (not present as a null value) to match that exact original behavior.
 * `$uStopFilter`/`$uStartFilter` are mutually exclusive (filter active
 * or not), and `$uLogin`/`$uLostPassword`/`$authorizeRemembering`/
 * `$uRegister` are mutually exclusive with `$username`/`$uProfile`/
 * `$uLogout`/`$uAdmin` (guest vs. identified user).
 *
 * `$loginRedirect` is the exception: it is unconditional, because the
 * login form's hidden `redirect` field is always rendered. It carries
 * the raw request URI -- `menubar_identification.latte` applies its own
 * `|urlencode`, the same way `HtmlService::accessDenied()` encodes at
 * its own redirect boundary.
 */
final readonly class MenubarIdentificationPageContext implements TemplatePageContext
{
    public function __construct(
        public ?string $querySearch,
        public ?string $uStopFilter,
        public ?string $uStartFilter,
        public ?string $uLogin,
        public ?string $uLostPassword,
        public ?bool $authorizeRemembering,
        public ?string $uRegister,
        public ?string $username,
        public ?string $uProfile,
        public ?string $uLogout,
        public ?string $uAdmin,
        public string $loginRedirect,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'LOGIN_REDIRECT' => $this->loginRedirect,
        ];

        if ($this->querySearch !== null) {
            $result['QUERY_SEARCH'] = $this->querySearch;
        }

        if ($this->uStopFilter !== null) {
            $result['U_STOP_FILTER'] = $this->uStopFilter;
        }

        if ($this->uStartFilter !== null) {
            $result['U_START_FILTER'] = $this->uStartFilter;
        }

        if ($this->uLogin !== null) {
            $result['U_LOGIN'] = $this->uLogin;
        }

        if ($this->uLostPassword !== null) {
            $result['U_LOST_PASSWORD'] = $this->uLostPassword;
        }

        if ($this->authorizeRemembering !== null) {
            $result['AUTHORIZE_REMEMBERING'] = $this->authorizeRemembering;
        }

        if ($this->uRegister !== null) {
            $result['U_REGISTER'] = $this->uRegister;
        }

        if ($this->username !== null) {
            $result['USERNAME'] = $this->username;
        }

        if ($this->uProfile !== null) {
            $result['U_PROFILE'] = $this->uProfile;
        }

        if ($this->uLogout !== null) {
            $result['U_LOGOUT'] = $this->uLogout;
        }

        if ($this->uAdmin !== null) {
            $result['U_ADMIN'] = $this->uAdmin;
        }

        return $result;
    }
}
