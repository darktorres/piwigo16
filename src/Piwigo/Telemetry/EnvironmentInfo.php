<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

final readonly class EnvironmentInfo
{
    public function __construct(
        public string $piwigoVersion,
        public string $phpVersion,
        public string $osFamily,
    ) {}

    /**
     * @return array{piwigo_version: string, php_version: string, os_family: string}
     */
    public function toArray(): array
    {
        return [
            'piwigo_version' => $this->piwigoVersion,
            'php_version' => $this->phpVersion,
            'os_family' => $this->osFamily,
        ];
    }
}
