<?php

declare(strict_types=1);

namespace Piwigo\Template\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Template\ThemeChainEntry;

/**
 * The two template variables `Template::setTheme()` assigns for the
 * resolved theme chain (P58-A).
 *
 * They used to go out through bare `assign()` calls, which
 * `phpstan-latte:sync-vartype` cannot see -- it derives a template's
 * `{varType}` block from `TemplatePageContext::toArray()` and View
 * properties -- so `$themes` reached the admin layout as `mixed` and its
 * `{foreach $themes as $theme}` read `$theme['id']` off it. Going through
 * a context is what makes the type reach the template, which is §9's rule
 * for the `{varType}` layouts: they cannot carry a `{templateType}` of
 * their own, so the ambient producer is where the type has to be stated.
 *
 * `$themeconf` stays `array<string, mixed>`: it is the merged contents of
 * each theme's own `themeconf.inc.php`, an open-ended per-theme bag rather
 * than a fixed shape, and typing it is not this change's job.
 */
final readonly class ThemeChainPageContext implements TemplatePageContext
{
    /**
     * @param list<ThemeChainEntry> $themes parent-first
     * @param array<string, mixed> $themeconf
     */
    public function __construct(
        public array $themes,
        public array $themeconf,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'themes' => $this->themes,
            'themeconf' => $this->themeconf,
        ];
    }
}
