<?php

declare(strict_types=1);

namespace Piwigo\Site\Projection;

/**
 * {@see \Piwigo\Site\LocalSiteReader::get_element_update_attributes()}'s
 * own fixed result shape.
 */
final readonly class ElementUpdateAttributes
{
    public function __construct(
        public ?string $representativeExt,
    ) {}

    /**
     * {@see \Piwigo\Controller\Admin\SiteUpdateSubController}'s own real
     * consumer splices an `id` key onto this before batch-writing it --
     * unbox to array at that mutation boundary.
     *
     * @return array{representative_ext: ?string}
     */
    public function toArray(): array
    {
        return [
            'representative_ext' => $this->representativeExt,
        ];
    }
}
