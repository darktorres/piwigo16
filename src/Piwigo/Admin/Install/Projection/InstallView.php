<?php

declare(strict_types=1);

namespace Piwigo\Admin\Install\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
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
 * absent array key. `$themes` is the ambient theme-chain `themes`
 * template var `Template::setTheme()` always assigns (regardless of
 * `applyThemeBase`) -- the controller reads it back via
 * `$template->getTemplateVars('themes')`, the same ambient-value
 * pattern every other P42-B ambient case uses.
 */
#[Template('install.latte')]
final readonly class InstallView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, string> $languageOptions
     * @param array<int, string>|null $errors
     * @param array<int, string>|null $infos
     * @param list<mixed> $themes
     * @param list<string> $dedupErrorStrings
     * @param list<array{path: string, label: string, writable: bool}> $writableChecks
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
        public bool $fSendCredentialsByMail,
        public string $lInstallHelp,
        public ?bool $install,
        public ?array $errors,
        public ?array $infos,
        public array $themes,
        public array $dedupErrorStrings,
        public ?bool $hasExistingInstall,
        public ?string $overwriteToken,
        public array $writableChecks,
    ) {}

    /**
     * `install.latte`'s own `{foreach $themes as $theme}{if
     * $theme['load_css']}{do combineCss(...)}{/if}{/foreach}` (a real
     * loop+conditional, not a fixed literal list) plus 4 unconditional
     * `{do combineScript(...)}`x3/`{do combineCss(...)}`x1
     * (docs/PLAN.md's P42-B). Deliberately narrower than
     * `ThemeBaseAssets::forAdminLayout()` -- this page never went
     * through the automatic theme-base wiring (`applyThemeBase: false`,
     * see `InstallWizard::boot()`'s own docblock), and never registered
     * `fontello.css`/`utilities.css`/`general.css` even before this
     * migration, only each theme's own `theme.css`.
     */
    #[Override]
    public function pageAssets(): array
    {
        $assets = [];

        foreach ($this->themes as $theme) {
            if (! is_array($theme)) {
                continue;
            }

            $id = $theme['id'] ?? null;
            if (($theme['load_css'] ?? false) === true && is_string($id)) {
                $assets[] = AssetContribution::css('themes/admin/' . $id . '/theme.css', order: -10);
            }
        }

        $assets[] = AssetContribution::script('jquery', 'https://cdn.jsdelivr.net/npm/jquery@1.11.3/dist/jquery.min.js');
        $assets[] = AssetContribution::css('themes/admin/default/css/pages/install.css', id: 'install');
        $assets[] = AssetContribution::script('jquery.cluetip', 'https://cdn.jsdelivr.net/gh/kswedberg/jquery-cluetip@1.2.6/jquery.cluetip.js', loadMode: LoadMode::Async, dependsOn: ['jquery']);
        $assets[] = AssetContribution::script('install', 'themes/admin/default/js/install.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.cluetip']);
        // Normally auto-registered by theme-base wiring (Template::
        // finalizeHtml(), gated on $themeBaseApplied) -- this page opts out
        // of that (applyThemeBase: false), so install.js's own
        // pwg_getPageString() calls need this registered explicitly.
        $assets[] = AssetContribution::script('page-data', 'themes/default/js/page-data.ts', loadMode: LoadMode::Footer);

        return $assets;
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Testing connection...',
            'Connection successful',
            'Connected to the database, but couldn\'t verify whether it already contains a Piwigo installation — check the database user\'s privileges to list tables',
            // Same 3 strings analyzeForm() validates server-side --
            // install.js mirrors these checks inline near their own
            // field, reusing the exact same translated text.
            'webmaster login can\'t contain characters \' or "',
            'please enter your password again',
            'mail address must be like xxx@yyy.eee (example : jack@altern.org)',
        ];
    }
}
