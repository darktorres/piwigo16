<?php

declare(strict_types=1);

namespace Piwigo\Page\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Page\PageTailRenderer::prepareTail()}. `$contactMail`
 * and `$toggleMobileThemeUrl` are genuinely optional -- the original
 * code only ever assigns those 2 template keys under their own runtime
 * condition, omitted here (not present as a null value) to match that
 * exact original behavior. `$debug` is always assigned (even when
 * entirely empty) -- see {@see \Piwigo\Page\Projection\DebugInfo}.
 */
final readonly class PageTailPageContext implements TemplatePageContext
{
    public function __construct(
        public string $version,
        public string $phpwgUrl,
        public string $vitalsScriptUrl,
        public ?string $contactMail,
        public DebugInfo $debug,
        public ?string $toggleMobileThemeUrl,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'VERSION' => $this->version,
            'APP_URL' => $this->phpwgUrl,
            'VITALS_SCRIPT_URL' => $this->vitalsScriptUrl,
            'debug' => $this->debug,
        ];

        if ($this->contactMail !== null) {
            $result['CONTACT_MAIL'] = $this->contactMail;
        }

        if ($this->toggleMobileThemeUrl !== null) {
            $result['TOGGLE_MOBILE_THEME_URL'] = $this->toggleMobileThemeUrl;
        }

        return $result;
    }
}
