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
        // readManifest() validates this is present/well-formed; restore()
        // only drives behavior off $included today.
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        public string $createdAt,
        public array $included,
    ) {}
}
