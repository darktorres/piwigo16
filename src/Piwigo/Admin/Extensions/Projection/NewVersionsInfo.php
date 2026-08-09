<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions\Projection;

/**
 * {@see \Piwigo\Admin\Extensions\CoreUpdateService::getPiwigoNewVersions()}'s
 * own fixed result shape -- `minor`/`major`/`minorPhp`/`majorPhp` are only
 * ever populated together in the pairs the method itself already
 * guarantees (`minorPhp` never set without `minor`, `majorPhp` never set
 * without `major`), matching the original array's own optional-key
 * convention.
 */
final readonly class NewVersionsInfo
{
    public function __construct(
        public bool $piwigoOrgChecked,
        public bool $isDev,
        public ?string $minor = null,
        public ?string $major = null,
        public ?string $minorPhp = null,
        public ?string $majorPhp = null,
    ) {}
}
