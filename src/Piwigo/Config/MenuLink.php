<?php

declare(strict_types=1);

namespace Piwigo\Config;

/**
 * A single external navigation link shown in the gallery menu. Matches
 * every field Menu\MenubarRenderer's own links-block loop reads.
 */
final readonly class MenuLink
{
    public function __construct(
        public string $label,
        public ?string $evalVisible,
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
            return new self(label: $value, evalVisible: null, newWindow: true, nwName: '', nwFeatures: '');
        }

        $label = $value['label'] ?? null;
        $evalVisible = $value['eval_visible'] ?? null;
        $nwName = $value['nw_name'] ?? null;
        $nwFeatures = $value['nw_features'] ?? null;

        return new self(
            label: is_string($label) ? $label : '',
            evalVisible: is_string($evalVisible) ? $evalVisible : null,
            newWindow: ! isset($value['new_window']) || (bool) $value['new_window'],
            nwName: is_string($nwName) ? $nwName : '',
            nwFeatures: is_string($nwFeatures) ? $nwFeatures : '',
        );
    }
}
