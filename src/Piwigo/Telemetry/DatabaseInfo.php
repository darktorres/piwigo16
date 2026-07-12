<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

final readonly class DatabaseInfo
{
    public function __construct(
        public string $driver,
        public string $serverVersion,
    ) {}

    /**
     * @return array{driver: string, server_version: string}
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'server_version' => $this->serverVersion,
        ];
    }
}
