<?php

declare(strict_types=1);

namespace Piwigo\Admin\Install\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated `$_GET`/`$_POST` shape for InstallWizard::boot()/render()
 * (replaces install.php's top-level `$_POST`/`$_GET` narrowing).
 *
 * `languageParam` stays a raw (only `strip_tags()`-normalized) nullable
 * string -- the original's fallback-to-browser-language logic depends on
 * `$this->fsLanguages` (a filesystem scan result) and
 * `$_SERVER['HTTP_ACCEPT_LANGUAGE']`, neither of which is request-shape
 * data this DTO has any business resolving; `boot()` keeps that
 * resolution itself.
 *
 * `isNewsletterSubscribe` defaults to `true` when `install` wasn't
 * submitted (matching the original's own property default, never
 * touched outside the `isset($_POST['install'])` branch), and only
 * reflects the checkbox's own presence once `install` was submitted.
 */
final readonly class InstallWizardRequest
{
    private function __construct(
        public string $dbhost,
        public string $dbuser,
        public string $dbpasswd,
        public string $dbname,
        public string $dbdriver,
        public ?int $dbport,
        public string $adminName,
        public string $adminPass1,
        public string $adminPass2,
        public string $adminMail,
        public bool $isInstallSubmitted,
        public bool $isNewsletterSubscribe,
        public ?string $languageParam,
        public bool $isSendCredentialsByMail,
        public bool $isAjaxDbCheck,
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
        $inputValidator->validate('dbdriver', $post, false, '/^(mysqli|pgsql)$/');
        $inputValidator->validate('dbport', $post, false, '/^\d{1,5}$/');

        $dbhost_raw = $post['dbhost'] ?? null;
        $dbhost = (is_string($dbhost_raw) && $dbhost_raw !== '') ? $dbhost_raw : 'localhost';
        $dbuser_raw = $post['dbuser'] ?? null;
        $dbuser = (is_string($dbuser_raw) && $dbuser_raw !== '') ? $dbuser_raw : '';
        $dbpasswd_raw = $post['dbpasswd'] ?? null;
        $dbpasswd = (is_string($dbpasswd_raw) && $dbpasswd_raw !== '') ? $dbpasswd_raw : '';
        $dbname_raw = $post['dbname'] ?? null;
        $dbname = (is_string($dbname_raw) && $dbname_raw !== '') ? $dbname_raw : '';
        $dbdriver_raw = $post['dbdriver'] ?? null;
        $dbdriver = (is_string($dbdriver_raw) && $dbdriver_raw === 'pgsql') ? 'pgsql' : 'mysqli';
        $dbport_raw = $post['dbport'] ?? null;
        $dbport = (is_string($dbport_raw) && $dbport_raw !== '' && is_numeric($dbport_raw)) ? (int) $dbport_raw : null;

        $admin_name_raw = $post['admin_name'] ?? null;
        $admin_name = (is_string($admin_name_raw) && $admin_name_raw !== '') ? $admin_name_raw : '';
        $admin_pass1_raw = $post['admin_pass1'] ?? null;
        $admin_pass1 = (is_string($admin_pass1_raw) && $admin_pass1_raw !== '') ? $admin_pass1_raw : '';
        $admin_pass2_raw = $post['admin_pass2'] ?? null;
        $admin_pass2 = (is_string($admin_pass2_raw) && $admin_pass2_raw !== '') ? $admin_pass2_raw : '';
        $admin_mail_raw = $post['admin_mail'] ?? null;
        $admin_mail = (is_string($admin_mail_raw) && $admin_mail_raw !== '') ? $admin_mail_raw : '';

        $is_install_submitted = isset($post['install']);
        $is_newsletter_subscribe = $is_install_submitted ? isset($post['newsletter_subscribe']) : true;

        $language_param = null;
        if (isset($get['language']) && is_string($get['language'])) {
            $language_param = strip_tags($get['language']);
        }

        $is_ajax_db_check = isset($get['ajax']) && $get['ajax'] === 'check-db';

        return new self(
            $dbhost,
            $dbuser,
            $dbpasswd,
            $dbname,
            $dbdriver,
            $dbport,
            $admin_name,
            $admin_pass1,
            $admin_pass2,
            $admin_mail,
            $is_install_submitted,
            $is_newsletter_subscribe,
            $language_param,
            isset($post['send_credentials_by_mail']),
            $is_ajax_db_check,
        );
    }
}
