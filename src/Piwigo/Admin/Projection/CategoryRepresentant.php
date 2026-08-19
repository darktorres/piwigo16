<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Category\Projection\CategoryRepresentantProperties;

/**
 * `cat_modify.latte`'s `representant` template variable, built by
 * {@see \Piwigo\Admin\CatModifyPageRenderer::render()}. `$picture` is
 * genuinely optional -- only set when the category has a real, non-empty
 * `representative_picture_id`; `$allowDelete` is genuinely optional too,
 * both omitted here (not present as `null`/`false`) to match the
 * original code's own per-key conditional `array` assignment exactly.
 */
final readonly class CategoryRepresentant
{
    public function __construct(
        public ?CategoryRepresentantProperties $picture,
        public bool $allowSetRandom,
        public ?bool $allowDelete,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->picture instanceof CategoryRepresentantProperties) {
            $result['picture'] = $this->picture->toArray();
        }

        $result['ALLOW_SET_RANDOM'] = $this->allowSetRandom;

        if ($this->allowDelete !== null) {
            $result['ALLOW_DELETE'] = $this->allowDelete;
        }

        return $result;
    }
}
