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
    public function __construct(
        public ?string $load_mode = null,
        public string $rootPath = '',
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
            AssetContribution::script('jquery.ui.timepicker-addon', 'themes/default/js/ui/jquery.ui.timepicker-addon.js', loadMode: $loadMode, dependsOn: ['jquery.ui.datepicker', 'jquery.ui.slider']),
        ];

        $datepickerLanguagePath = 'themes/default/js/ui/i18n/jquery.ui.datepicker-' . $this->jqueryCode . '.js';
        if (file_exists($this->rootPath . $datepickerLanguagePath)) {
            $assets[] = AssetContribution::script('jquery.ui.datepicker-' . $this->jqueryCode, $datepickerLanguagePath, loadMode: $loadMode, dependsOn: ['jquery.ui.datepicker']);
            $require[] = 'jquery.ui.datepicker-' . $this->jqueryCode;
        }

        $timepickerLanguagePath = 'themes/default/js/ui/i18n/jquery.ui.timepicker-' . $this->jqueryCode . '.js';
        if (file_exists($this->rootPath . $timepickerLanguagePath)) {
            $assets[] = AssetContribution::script('jquery.ui.timepicker-' . $this->jqueryCode, $timepickerLanguagePath, loadMode: $loadMode, dependsOn: ['jquery.ui.timepicker-addon']);
            $require[] = 'jquery.ui.timepicker-' . $this->jqueryCode;
        }

        $assets[] = AssetContribution::script('datepicker', 'themes/admin/default/js/datepicker.js', loadMode: $loadMode, dependsOn: $require);

        $assets[] = AssetContribution::css('themes/default/js/ui/theme/jquery.ui.theme.css');
        $assets[] = AssetContribution::css('themes/default/js/ui/theme/jquery.ui.slider.css');
        $assets[] = AssetContribution::css('themes/default/js/ui/theme/jquery.ui.datepicker.css');
        $assets[] = AssetContribution::css('themes/default/js/ui/theme/jquery.ui.timepicker-addon.css');

        return $assets;
    }
}
