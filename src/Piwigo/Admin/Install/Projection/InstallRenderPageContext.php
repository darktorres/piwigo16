<?php

declare(strict_types=1);

namespace Piwigo\Admin\Install\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\Install\InstallWizard::render()}. `$languageSelection`,
 * `$install`, `$errors`, and `$infos` are genuinely optional -- the
 * original code only ever assigns those 4 template keys under their own
 * runtime condition, omitted here (not present as a null value) to match
 * that exact original behavior.
 */
final readonly class InstallRenderPageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $languageOptions
     * @param array<int, string>|null $errors
     * @param array<int, string>|null $infos
     */
    public function __construct(
        public ?string $languageSelection,
        public array $languageOptions,
        public string $tContentEncoding,
        public string $release,
        public string $fAction,
        public string $fDbHost,
        public string $fDbUser,
        public string $fDbName,
        public string $fDbPrefix,
        public string $fDbDriver,
        public ?int $fDbPort,
        public string $fAdmin,
        public string $fAdminEmail,
        public string $email,
        public bool $fNewsletterSubscribe,
        public string $lInstallHelp,
        public ?bool $install,
        public ?array $errors,
        public ?array $infos,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'language_options' => $this->languageOptions,
            'T_CONTENT_ENCODING' => $this->tContentEncoding,
            'RELEASE' => $this->release,
            'F_ACTION' => $this->fAction,
            'F_DB_HOST' => $this->fDbHost,
            'F_DB_USER' => $this->fDbUser,
            'F_DB_NAME' => $this->fDbName,
            'F_DB_PREFIX' => $this->fDbPrefix,
            'F_DB_DRIVER' => $this->fDbDriver,
            'F_DB_PORT' => $this->fDbPort,
            'F_ADMIN' => $this->fAdmin,
            'F_ADMIN_EMAIL' => $this->fAdminEmail,
            'EMAIL' => $this->email,
            'F_NEWSLETTER_SUBSCRIBE' => $this->fNewsletterSubscribe,
            'L_INSTALL_HELP' => $this->lInstallHelp,
        ];

        if ($this->languageSelection !== null) {
            $result['language_selection'] = $this->languageSelection;
        }

        if ($this->install !== null) {
            $result['install'] = $this->install;
        }

        if ($this->errors !== null) {
            $result['errors'] = $this->errors;
        }

        if ($this->infos !== null) {
            $result['infos'] = $this->infos;
        }

        return $result;
    }
}
