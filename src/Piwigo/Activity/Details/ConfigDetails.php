<?php

declare(strict_types=1);

namespace Piwigo\Activity\Details;

use Piwigo\Activity\ActivityDetails;

/** Payload for System Config events — records which config section (and optional action) changed. */
final readonly class ConfigDetails implements ActivityDetails
{
    public function __construct(
        public string  $configSection,
        public ?string $configAction = null,
    ) {
    }

    #[\Override]
    public function toJsonArray(): array
    {
        $out = ['config_section' => $this->configSection];
        if ($this->configAction !== null) {
            $out['config_action'] = $this->configAction;
        }
        return $out;
    }
}
