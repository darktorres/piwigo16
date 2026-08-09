<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for PasswordController's `__invoke()`
 * and its private handler methods (replaces password.php).
 *
 * `keyPresent`/`key` expose the raw `$_GET['key']` state -- the original
 * mutated the superglobal itself (`unset($_GET['key'])`) once a
 * logged-in non-guest user had it set, so every later `isset($_GET['key'])`
 * re-check observed the post-mutation state; the controller now computes
 * that same "effective key" once as a local/instance value instead of
 * relying on a shared mutable global.
 *
 * `usernameOrEmailPresent` matches the original's own combined
 * `isset(...) and is_string(...)` display gate exactly (an intentionally
 * submitted empty string still passes); `usernameOrEmail` is reused,
 * trimmed at the call site, by the separate lookup path in
 * `processVerificationCode()`.
 */
final readonly class PasswordRequest
{
    private function __construct(
        public ?string $action,
        public bool $isSubmitted,
        public bool $keyPresent,
        public ?string $key,
        public bool $usernameOrEmailPresent,
        public string $usernameOrEmail,
        public string $userCode,
        public string $newPassword,
        public string $passwordConf,
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
        $inputValidator
            ->validate('action', $get, false, '/^(lost|reset|lost_code|reset_end|none)$/');
        $action_raw = $get['action'] ?? null;
        $action = is_string($action_raw) ? $action_raw : null;

        $key_present = isset($get['key']);
        $key_raw = $get['key'] ?? null;
        $key = is_string($key_raw) ? $key_raw : null;

        $username_or_email_raw = $post['username_or_email'] ?? null;
        $username_or_email_present = isset($post['username_or_email']) && is_string($username_or_email_raw);
        $username_or_email = is_string($username_or_email_raw) ? $username_or_email_raw : '';

        $user_code_raw = $post['user_code'] ?? '';
        $user_code = is_string($user_code_raw) ? $user_code_raw : '';

        $new_password_raw = $post['use_new_pwd'] ?? '';
        $new_password = is_string($new_password_raw) ? $new_password_raw : '';

        $password_conf_raw = $post['passwordConf'] ?? '';
        $password_conf = is_string($password_conf_raw) ? $password_conf_raw : '';

        return new self(
            $action,
            isset($post['submit']),
            $key_present,
            $key,
            $username_or_email_present,
            $username_or_email,
            $user_code,
            $new_password,
            $password_conf,
        );
    }
}
