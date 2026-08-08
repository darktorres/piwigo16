<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance\Projection;

/**
 * {@see \Piwigo\Admin\Maintenance\ServerInfoService::curatedInfo()}'s own
 * fixed result shape.
 */
final readonly class ServerInfo
{
    /**
     * @param list<string> $extensions
     * @param array<string, string> $ini
     */
    public function __construct(
        public string $phpVersion,
        public string $sapi,
        public string $os,
        public array $extensions,
        public array $ini,
    ) {}
}
