<?php

declare(strict_types=1);

namespace Piwigo\Admin\Install\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;
use Piwigo\Template\ThemeChainEntry;

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
 *
 * `$lInstallHelp` is Html, not string (P59): a `Lang::t()` translation
 * whose `%s` substitution is a hardcoded `AppInfo::URL` constant, never
 * user-supplied text.
 */
#[Template('install.latte')]
final readonly class InstallView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, string> $languageOptions
     * @param array<int, string>|null $errors
     * @param array<int, string>|null $infos
     * @param list<ThemeChainEntry> $themes
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
        public Html $lInstallHelp,
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
     * $theme->loadCss}{do combineCss(...)}{/if}{/foreach}` (a real
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
            if ($theme->loadCss) {
                $assets[] = AssetContribution::css('themes/admin/' . $theme->id . '/theme.css', order: -10);
            }
        }

        $assets[] = AssetContribution::css('themes/admin/default/css/pages/install.css', id: 'install');
        // This page opts out of the theme-base wiring that would normally
        // auto-register page-data (Template::finalizeHtml(), gated on
        // $themeBaseApplied), so install.ts's own pwg_getPageString() calls
        // used to need a standalone page-data registration here. They no
        // longer do: install.ts imports page-data directly, so its code is
        // folded into this bundle (docs/PLAN.md P48).
        //
        // Keeping that registration was in fact actively broken. P48 also
        // removed pageData.ts as a Vite entry, so it has no manifest
        // entry, and PageAssets::resolvePath() falls back to the raw
        // source path for anything it cannot resolve -- meaning this page
        // emitted `<script src="themes/default/js/pageData.ts">` and
        // served the browser raw TypeScript.
        $assets[] = AssetContribution::script('install', 'themes/admin/default/js/install.ts', loadMode: LoadMode::Footer);

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
