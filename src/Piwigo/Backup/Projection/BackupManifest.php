<?php

declare(strict_types=1);

namespace Piwigo\Backup\Projection;

/**
 * {@see \Piwigo\Backup\BackupService}'s own `manifest.json` shape.
 */
final readonly class BackupManifest
{
    /**
     * @param list<string> $included
     */
    public function __construct(
        public string $createdAt,
        public string $dbPrefix,
        public array $included,
    ) {}
}
