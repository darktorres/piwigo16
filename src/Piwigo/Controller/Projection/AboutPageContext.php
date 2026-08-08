<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\AboutController}. `$themeAbout` is genuinely
 * optional: the original code only assigns the `THEME_ABOUT` key when
 * the active theme ships its own `about.html` -- omitted here (not
 * present as a null value) to match that exact original behavior.
 */
final readonly class AboutPageContext implements TemplatePageContext
{
    public function __construct(
        public string $aboutMessage,
        public ?string $themeAbout,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'ABOUT_MESSAGE' => $this->aboutMessage,
        ];
        if ($this->themeAbout !== null) {
            $result['THEME_ABOUT'] = $this->themeAbout;
        }

        return $result;
    }
}
