<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/admin/default/template/include/datepicker.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d.
 *
 * `$load_mode` is genuinely optional -- the template's own
 * `{if empty($load_mode)}{var $load_mode = 'footer'}{/if}` default
 * applies whenever a real call site omits it (most do). `$rootPath`/
 * `$jqueryCode` (docs/PLAN.md's P42-B) replace the file's own
 * `$ROOT_PATH`/`$lang_info['jquery_code']` ambient reads -- unlike
 * `pageAssets()`, this class is never reached via `Renderer::render()`'s
 * own hook (see that method's own docblock), so every real parent
 * resolves and passes both explicitly, the same way `UserListView`'s
 * own `$rootUrl`/`$colorscheme` ambient-value pattern already does.
 */
#[TemplateAttr('include/datepicker.inc.latte')]
final readonly class DatepickerView implements View, HasPageAssets
{
    /**
     * The real, upstream `jquery/jquery-ui` v1.10.4 tag's own
     * `ui/i18n/` directory listing (confirmed identical to the vendored
     * copy before the CDN migration deleted it -- npm's own published
     * `jquery-ui` package drops these files entirely, so this list
     * comes from the real GitHub repo, not npm). Replaces a real
     * `file_exists()` gate that can no longer work once the locale
     * file is served from a CDN, not a local path (docs/PLAN.md P46's
     * vendor-CDN migration).
     *
     * @var list<string>
     */
    private const array DATEPICKER_LOCALES = [
        'af', 'ar', 'ar-DZ', 'az', 'bg', 'bs', 'ca', 'cs', 'cy-GB', 'da',
        'de', 'el', 'en-AU', 'en-GB', 'en-NZ', 'eo', 'es', 'et', 'eu',
        'fa', 'fi', 'fo', 'fr', 'fr-CH', 'gl', 'he', 'hi', 'hr', 'hu',
        'hy', 'id', 'is', 'it', 'ja', 'ka', 'kk', 'km', 'ko', 'lb', 'lt',
        'lv', 'mk', 'ml', 'ms', 'nl', 'no', 'pl', 'pt', 'pt-BR', 'rm',
        'ro', 'ru', 'sk', 'sl', 'sq', 'sr', 'sr-SR', 'sv', 'ta', 'th',
        'tj', 'tr', 'uk', 'vi', 'zh-CN', 'zh-HK', 'zh-TW',
    ];

    /**
     * Same shape as `DATEPICKER_LOCALES` above, but the real, upstream
     * `trentrichardson/jQuery-Timepicker-Addon` v1.4.4 tag's own
     * `dist/i18n/` directory listing instead (a distinct package from
     * jQuery UI core itself, confirmed via byte-identical content
     * before this migration).
     *
     * @var list<string>
     */
    private const array TIMEPICKER_LOCALES = [
        'af', 'am', 'bg', 'ca', 'cs', 'da', 'de', 'el', 'es', 'et', 'eu',
        'fi', 'fr', 'gl', 'he', 'hr', 'hu', 'id', 'it', 'ja', 'ko', 'lt',
        'nl', 'no', 'pl', 'pt', 'pt-BR', 'ro', 'ru', 'sk', 'sr-RS',
        'sr-YU', 'sv', 'th', 'tr', 'uk', 'vi', 'zh-CN', 'zh-TW',
    ];

    public function __construct(
        public ?string $load_mode = null,
        public string $jqueryCode = '',
    ) {}

    /**
     * Replicates `datepicker.inc.latte`'s own `file_exists()`-gated
     * per-language script registrations exactly -- a real derived
     * value, not a fixed literal list (docs/PLAN.md's P42's testing
     * discipline), covered by `DatepickerViewTest`.
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        $loadMode = match ($this->load_mode) {
            'header' => LoadMode::Header,
            'async' => LoadMode::Async,
            default => LoadMode::Footer,
        };

        $require = ['jquery.ui.timepicker-addon'];
        $assets = [
            AssetContribution::script('jquery.ui.timepicker-addon', 'https://cdn.jsdelivr.net/gh/trentrichardson/jQuery-Timepicker-Addon@v1.4.4/dist/jquery-ui-timepicker-addon.js', loadMode: $loadMode, dependsOn: ['jquery.ui']),
        ];

        if (in_array($this->jqueryCode, self::DATEPICKER_LOCALES, true)) {
            $assets[] = AssetContribution::script('jquery.ui.datepicker-' . $this->jqueryCode, 'https://cdn.jsdelivr.net/gh/jquery/jquery-ui@1.10.4/ui/i18n/jquery.ui.datepicker-' . $this->jqueryCode . '.js', loadMode: $loadMode, dependsOn: ['jquery.ui']);
            $require[] = 'jquery.ui.datepicker-' . $this->jqueryCode;
        }

        if (in_array($this->jqueryCode, self::TIMEPICKER_LOCALES, true)) {
            $assets[] = AssetContribution::script('jquery.ui.timepicker-' . $this->jqueryCode, 'https://cdn.jsdelivr.net/gh/trentrichardson/jQuery-Timepicker-Addon@v1.4.4/dist/i18n/jquery-ui-timepicker-' . $this->jqueryCode . '.js', loadMode: $loadMode, dependsOn: ['jquery.ui.timepicker-addon']);
            $require[] = 'jquery.ui.timepicker-' . $this->jqueryCode;
        }

        $assets[] = AssetContribution::script('datepicker', 'themes/admin/default/js/datepicker.ts', loadMode: $loadMode, dependsOn: $require);

        $assets[] = AssetContribution::css('https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.10.4/css/jquery-ui.css', id: 'jquery.ui');
        $assets[] = AssetContribution::css('https://cdn.jsdelivr.net/gh/trentrichardson/jQuery-Timepicker-Addon@v1.4.4/dist/jquery-ui-timepicker-addon.min.css');

        return $assets;
    }
}
