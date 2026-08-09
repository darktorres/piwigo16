<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\PictureCoiPageRenderer::render()}. `$coi` is
 * genuinely optional: the original code only ever populates the `coi`
 * template key when the image row's own `coi` column is a real,
 * non-empty crop-of-interest string -- omitted here (not present as a
 * null value) to match that exact original behavior. `$croppedDerivatives`
 * is always included -- `picture_coi.tpl`'s own
 * `{foreach from=$cropped_derivatives}` has no guard around it.
 */
final readonly class PictureCoiPageContext implements TemplatePageContext
{
    /**
     * @param array{l: float, t: float, r: float, b: float}|null $coi
     * @param list<array{U_IMG: string, HTM_SIZE: string}> $croppedDerivatives
     */
    public function __construct(
        public string $title,
        public string $alt,
        public string $imgUrl,
        public ?array $coi,
        public array $croppedDerivatives,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'TITLE' => $this->title,
            'ALT' => $this->alt,
            'U_IMG' => $this->imgUrl,
            'cropped_derivatives' => $this->croppedDerivatives,
        ];
        if ($this->coi !== null) {
            $result['coi'] = $this->coi;
        }

        return $result;
    }
}
