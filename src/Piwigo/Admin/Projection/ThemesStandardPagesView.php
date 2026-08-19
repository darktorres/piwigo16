<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `themes_standard_pages.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\ThemesStandardPagesPageRenderer::render()}. No
 * `$stdPgsLogoOptions` field -- the template's own body never
 * references it (its 4 logo radio options are hardcoded literals, not
 * looped; the PHP-side list only ever validates the submitted value in
 * ThemesStandardPagesSubmitRequest::fromGlobals()).
 */
#[Template('themes_standard_pages.latte')]
final readonly class ThemesStandardPagesView implements View
{
    /**
     * @param list<string> $stdPgsSkinOptions
     * @param list<mixed> $standardPagesUsedBy
     */
    public function __construct(
        public bool $useStandardPages,
        public string $stdPgsSelectedLogo,
        public string $stdPgsSelectedSkin,
        public array $stdPgsSkinOptions,
        public bool $isStandardPagesUsed,
        public array $standardPagesUsedBy,
        public ?string $stdPgsSelectedLogoPath,
        public string $csrfToken,
        public int $isWebmaster,
        public ?string $saveError,
    ) {}
}
