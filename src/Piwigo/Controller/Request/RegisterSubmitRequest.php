<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

/**
 * Validated `$_POST` shape for RegisterController::__invoke() (the
 * self-registration form/handler, replacing register.php) -- P27/SEC-40
 * Request DTO.
 *
 * `password`/`passwordConf`/`login` normalize a non-string raw value to
 * `''` rather than keeping the original's `null`-or-mixed passthrough.
 * The original's "is it missing?" checks (`=== null || === '' || === '0'`)
 * already treated absent/empty/`'0'` identically, and its "is it a
 * match?" checks already cast a non-string to `''` before comparing --
 * the one behavior difference is a `password`/`password_conf` submitted
 * as a PHP array (e.g. `password[]=x`), which the original's `=== null`
 * check didn't catch (an array isn't `=== null`/`''`/`'0'`) even though it
 * was still cast down to `''` two lines later; normalizing here means
 * that case now correctly hits the "missing" branch instead of silently
 * falling through to the mismatch/passthrough logic with an empty
 * string. `login`/`mailAddress` are exposed once and reused by both the
 * original's in-block read (passed to `UserService::registerUser()`) and
 * its separate post-submit re-read (redisplayed via
 * `htmlspecialchars(stripslashes(...))`), rather than reading
 * `$_POST['login']`/`$_POST['mail_address']` from the superglobal twice.
 */
final readonly class RegisterSubmitRequest
{
    private function __construct(
        public bool $isSubmitted,
        public string $key,
        public string $password,
        public string $passwordConf,
        public string $login,
        public ?string $mailAddress,
        public bool $sendPasswordByMail,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_POST);
    }

    /**
     * @param array<string, mixed> $post
     */
    public static function fromArray(array $post): self
    {
        $key = $post['key'] ?? null;
        $password = $post['password'] ?? null;
        $passwordConf = $post['password_conf'] ?? null;
        $login = $post['login'] ?? null;
        $mailAddress = $post['mail_address'] ?? null;

        return new self(
            isset($post['submit']),
            is_string($key) ? $key : '',
            is_string($password) ? $password : '',
            is_string($passwordConf) ? $passwordConf : '',
            is_string($login) ? $login : '',
            is_string($mailAddress) ? $mailAddress : null,
            isset($post['send_password_by_mail']),
        );
    }
}
