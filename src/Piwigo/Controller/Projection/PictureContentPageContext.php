<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\PictureController::defaultPictureContent()}
 * -- see {@see PicturePageContext} for that controller's own
 * `__invoke()` assigns. `$uOriginal` is genuinely optional -- the
 * original code only assigns `U_ORIGINAL` when `$show_original` is true
 * and `element_url` is present, omitted here (not present as a null
 * value) to match that exact original behavior.
 */
final readonly class PictureContentPageContext implements TemplatePageContext
{
    public function __construct(
        public ?string $uOriginal,
        public string $altImg,
        public string $cookiePath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'ALT_IMG' => $this->altImg,
            'COOKIE_PATH' => $this->cookiePath,
        ];

        if ($this->uOriginal !== null) {
            $result['U_ORIGINAL'] = $this->uOriginal;
        }

        return $result;
    }
}
