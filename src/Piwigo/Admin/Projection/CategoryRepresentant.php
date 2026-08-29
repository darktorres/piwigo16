<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Category\Projection\CategoryRepresentantProperties;

/**
 * `cat_modify.latte`'s `representant` template variable, built by
 * {@see \Piwigo\Admin\CatModifyPageRenderer::render()}. `$picture` is
 * genuinely optional -- only set when the category has a real, non-empty
 * `representative_picture_id`.
 *
 * `$allowDelete` is a plain `bool`. It was `?bool` carrying only
 * `true`/`null`, which is the shape the pre-conversion Smarty needed:
 * the template asked `isset($representant.ALLOW_DELETE)` because a
 * missing array key was the only way to say "no". A real property has
 * no missing state, so the nullable added a third value neither the
 * producer nor the template ever used, and `isset()` on it read as a
 * presence check where the question is a permission.
 */
final readonly class CategoryRepresentant
{
    public function __construct(
        public ?CategoryRepresentantProperties $picture,
        public bool $allowSetRandom,
        public bool $allowDelete,
    ) {}
}
