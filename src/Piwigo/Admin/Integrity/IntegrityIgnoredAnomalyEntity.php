<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `integrity_ignored_anomalies` table. Replaces CheckIntegrity's
 * former `c13y_ignore` config blob (`{version, list: [md5 ids]}`, the whole
 * list discarded if the stored version didn't match AppInfo::VERSION)
 * with one row per (anomaly, version) -- same version-scoping semantics
 * (CheckIntegrity::check() only ever queries rows for the current version),
 * but each ignore now carries its own real timestamp instead of being lost
 * inside an all-or-nothing blob.
 */
#[ORM\Entity(repositoryClass: IntegrityIgnoredAnomalyRepository::class)]
#[ORM\Table(name: 'integrity_ignored_anomalies')]
final class IntegrityIgnoredAnomalyEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'anomaly_id', type: 'string', length: 64)]
        public string $anomalyId,
        #[ORM\Id]
        #[ORM\Column(name: 'piwigo_version', type: 'string', length: 16)]
        public string $piwigoVersion,
        #[ORM\Column(name: 'ignored_at', type: 'string', length: 19)]
        public string $ignoredAt,
    ) {}
}
