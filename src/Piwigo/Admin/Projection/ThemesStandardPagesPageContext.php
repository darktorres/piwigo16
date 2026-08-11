<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\ThemesStandardPagesPageRenderer::render()}.
 * `$saveError` is genuinely optional: the original code assigns it from
 * 3 mutually exclusive branches of the same logo-upload attempt (an
 * invalid MIME type, a write failure, or an unwritable upload
 * directory) -- at most one ever fires per render() call, omitted here
 * (not present as a null value) to match that exact original behavior.
 */
final readonly class ThemesStandardPagesPageContext implements TemplatePageContext
{
    /**
     * @param list<string> $stdPgsLogoOptions
     * @param list<string> $stdPgsSkinOptions
     * @param list<mixed> $standardPagesUsedBy
     */
    public function __construct(
        public bool $useStandardPages,
        public string $stdPgsSelectedLogo,
        public array $stdPgsLogoOptions,
        public string $stdPgsSelectedSkin,
        public array $stdPgsSkinOptions,
        public bool $isStandardPagesUsed,
        public array $standardPagesUsedBy,
        public ?string $stdPgsSelectedLogoPath,
        public string $pwgToken,
        public int $isWebmaster,
        public string $adminPageTitle,
        public ?string $saveError,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'use_standard_pages' => $this->useStandardPages,
            'std_pgs_selected_logo' => $this->stdPgsSelectedLogo,
            'std_pgs_logo_options' => $this->stdPgsLogoOptions,
            'std_pgs_selected_skin' => $this->stdPgsSelectedSkin,
            'std_pgs_skin_options' => $this->stdPgsSkinOptions,
            'is_standard_pages_used' => $this->isStandardPagesUsed,
            'standard_pages_used_by' => $this->standardPagesUsedBy,
            'std_pgs_selected_logo_path' => $this->stdPgsSelectedLogoPath,
            'CSRF_TOKEN' => $this->pwgToken,
            'isWebmaster' => $this->isWebmaster,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];

        if ($this->saveError !== null) {
            $result['save_error'] = $this->saveError;
        }

        return $result;
    }
}
