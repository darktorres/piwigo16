<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * {@see \Piwigo\Admin\ThemesInstalledPageRenderer::buildTplTheme()}'s own
 * per-theme row for `themes_installed.latte`. Narrowed to the fields the
 * template actually reads -- `PARENT` and `DEACTIVATE_TOOLTIP` are built by
 * `buildTplTheme()` but grepping the template confirmed neither is ever
 * read (the "Deactivate" link's tooltip is a hardcoded translated string,
 * not `DEACTIVATE_TOOLTIP` -- a real, pre-existing dead-data mismatch,
 * unrelated to this retype).
 *
 * `isDefault`/`deactivable` are only meaningful (and only included in
 * `toArray()`) when `state === 'active'`; `activable`/`activableTooltip`/
 * `deletable`/`deleteTooltip` only when it isn't -- matches the template's
 * own `isset($theme['IS_DEFAULT'])`/`$theme['STATE'] != "active"` guards.
 */
final readonly class ThemeListRow
{
    public function __construct(
        public string $id,
        public string $name,
        public string $visitUrl,
        public string $version,
        public string $desc,
        public string $author,
        public ?string $authorUrl,
        public bool $isMobile,
        public string $screenshot,
        public ?string $adminUri,
        public string $state,
        public bool $isDefault,
        public bool $deactivable,
        public bool $activable,
        public ?string $activableTooltip,
        public bool $deletable,
        public ?string $deleteTooltip,
    ) {}
}
