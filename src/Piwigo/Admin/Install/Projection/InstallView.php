<?php

declare(strict_types=1);

namespace Piwigo\Admin\Install\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `install.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\Install\InstallWizard::render()}. `install.latte` is a
 * genuinely self-contained document (its own `<!DOCTYPE html>`, not
 * something that parses against a shared header/footer) -- no
 * `{layout}` needed, unlike every other P41 page conversion.
 * `$languageSelection`/`$install`/`$errors`/`$infos` are genuinely
 * optional -- the original code only ever assigned those 4 template
 * keys under their own runtime condition; `install.latte`'s own body
 * gates on `isset()` for each, which still works correctly against a
 * real nullable property holding `null` the same way it did against an
 * absent array key.
 */
#[Template('install.latte')]
final readonly class InstallView implements View
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
}
