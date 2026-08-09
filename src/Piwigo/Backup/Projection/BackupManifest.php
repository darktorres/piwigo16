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
        // $createdAt/$dbPrefix: BackupService::readManifest() already
        // validates both are present and well-formed (confirms
        // manifest.json isn't corrupt/foreign); restore() itself only
        // drives behavior off $included today.
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        public string $createdAt,
        // @phpstan-ignore shipmonk.deadProperty.neverRead
        public string $dbPrefix,
        public array $included,
    ) {}
}
