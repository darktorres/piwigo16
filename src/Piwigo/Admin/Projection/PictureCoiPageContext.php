<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Image\Projection\CenterOfInterest;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\PictureCoiPageRenderer::render()}. `$coi` is
 * genuinely optional: the original code only ever populates the `coi`
 * template key when the image row's own `coi` column is a real,
 * non-empty crop-of-interest string -- omitted here (not present as a
 * null value) to match that exact original behavior. `$croppedDerivatives`
 * is always included -- `picture_coi.latte`'s own
 * `{foreach from=$cropped_derivatives}` has no guard around it.
 */
final readonly class PictureCoiPageContext implements TemplatePageContext
{
    /**
     * @param list<CroppedDerivativeLink> $croppedDerivatives
     */
    public function __construct(
        public string $title,
        public string $alt,
        public string $imgUrl,
        public ?CenterOfInterest $coi,
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
            'cropped_derivatives' => array_map(static fn (CroppedDerivativeLink $link): array => $link->toArray(), $this->croppedDerivatives),
        ];
        if ($this->coi instanceof CenterOfInterest) {
            $result['coi'] = $this->coi->toArray();
        }

        return $result;
    }
}
