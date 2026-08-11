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
 * exact original behavior. `$debug` stays a loose bag: it's built by
 * conditionally merging in `QUERIES_LIST` and/or `TIME`/`NB_QUERIES`/
 * `SQL_TIME` under 2 independent config flags, always assigned (even
 * when empty), never a single fixed shape.
 */
final readonly class PageTailPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $debug
     */
    public function __construct(
        public string $version,
        public string $phpwgUrl,
        public string $vitalsScriptUrl,
        public ?string $contactMail,
        public array $debug,
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
