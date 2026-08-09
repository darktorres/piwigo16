<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

use Piwigo\Auth\CookieService;
use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for IdentificationController::__invoke()
 * (replaces identification.php).
 *
 * `postRedirectDecoded` replaces the original's own
 * `$_POST['redirect_decoded'] = urldecode($_POST['redirect'])` write-then-
 * validate trick -- rather than mutating the superglobal just so a later
 * `InputValidator::validate()` call can read it back, this decodes once
 * and validates it directly against a synthetic single-key array
 * (`InputValidator::validate()` only ever reads `$paramArray[$paramName]`,
 * so this is behaviorally identical). The same decoded value is reused
 * for the controller's separate `$redirect_to` computation inside the
 * `login` branch, instead of re-reading and re-decoding `$_POST['redirect']`
 * a second time.
 */
final readonly class IdentificationSubmitRequest
{
    private function __construct(
        public ?string $postRedirectDecoded,
        public ?string $getRedirect,
        public bool $hideRedirectErrorPresent,
        public bool $isLoginSubmitted,
        public string $username,
        public ?string $password,
        public bool $isRememberMe,
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
        $post_redirect = $post['redirect'] ?? null;
        $post_redirect_decoded = is_string($post_redirect) ? urldecode($post_redirect) : null;

        // security (level 1): the redirect must occur within Piwigo, so the
        // redirect param must start with the relative home url
        $inputValidator
            ->validate(
                'redirect_decoded',
                [
                    'redirect_decoded' => $post_redirect_decoded,
                ],
                false,
                '{^' . preg_quote(new CookieService()->cookiePath()) . '}'
            );

        $get_redirect_raw = $get['redirect'] ?? null;
        $get_redirect = (is_string($get_redirect_raw) && $get_redirect_raw !== '') ? $get_redirect_raw : null;

        $username_raw = $post['username'] ?? null;
        $username = is_string($username_raw) ? $username_raw : '';

        $password_raw = $post['password'] ?? null;
        $password = is_string($password_raw) ? $password_raw : null;

        $remember_me_raw = $post['remember_me'] ?? null;
        $is_remember_me = isset($post['remember_me']) && is_string($remember_me_raw) && $remember_me_raw === '1';

        return new self(
            $post_redirect_decoded,
            $get_redirect,
            isset($get['hide_redirect_error']),
            isset($post['login']),
            $username,
            $password,
            $is_remember_me,
        );
    }
}
