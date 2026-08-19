<?php

declare(strict_types=1);

namespace Piwigo\Picture\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The `metadata` template variable assigned by
 * {@see \Piwigo\Picture\PictureMetadataRenderer::render()}. Genuinely
 * optional -- the EXIF and IPTC panels are 2 independent conditional
 * branches, each appending its own row only when real data was found;
 * omitted here (not present as an empty-array value) to match
 * `picture.latte`'s own `{if isset($metadata)}` guard exactly.
 */
final readonly class PictureMetadataPageContext implements TemplatePageContext
{
    /**
     * @param list<MetadataPanel>|null $metadata
     */
    public function __construct(
        public ?array $metadata,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [];

        if ($this->metadata !== null) {
            $result['metadata'] = array_map(static fn (MetadataPanel $panel): array => $panel->toArray(), $this->metadata);
        }

        return $result;
    }
}
