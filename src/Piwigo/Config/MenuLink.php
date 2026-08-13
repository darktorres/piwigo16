<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * A single external navigation link shown in the gallery menu. Matches
 * every field Menu\MenubarRenderer's own links-block loop reads.
 *
 * `$visibilityLinkId` replaces the old `$evalVisible` (SEC-49): a plain,
 * safely-storable identifier instead of raw `eval()`-able PHP source.
 * `null` means the link is always visible, matching `$evalVisible`'s own
 * old `null` short-circuit; a real identifier makes
 * Menu\MenubarRenderer::render() dispatch a
 * Menu\Event\CheckMenuLinkVisibility instead of `eval()`ing anything.
 */
final readonly class MenuLink
{
    public function __construct(
        public string $label,
        public ?string $visibilityLinkId,
        public bool $newWindow,
        public string $nwName,
        public string $nwFeatures,
    ) {}

    /**
     * A bare string is shorthand for a link whose only field is the
     * label -- matches MenubarRenderer's own existing
     * `if (! is_array($url_data)) { $url_data = ['label' => $url_data]; }`
     * branch.
     *
     * @param array<mixed>|string $value
     */
    public static function fromArray(array|string $value): self
    {
        if (is_string($value)) {
            return new self(label: $value, visibilityLinkId: null, newWindow: true, nwName: '', nwFeatures: '');
        }

        $label = $value['label'] ?? null;
        $visibilityLinkId = $value['visibility_link_id'] ?? null;
        $nwName = $value['nw_name'] ?? null;
        $nwFeatures = $value['nw_features'] ?? null;

        return new self(
            label: is_string($label) ? $label : '',
            visibilityLinkId: is_string($visibilityLinkId) ? $visibilityLinkId : null,
            newWindow: ! isset($value['new_window']) || (bool) $value['new_window'],
            nwName: is_string($nwName) ? $nwName : '',
            nwFeatures: is_string($nwFeatures) ? $nwFeatures : '',
        );
    }
}
