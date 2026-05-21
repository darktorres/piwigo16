<?php

declare(strict_types=1);

namespace Piwigo\Admin;

/** Piwigo version upgrade candidates resolved from piwigo.org. All fields are null when up-to-date. */
final readonly class AvailableVersions
{
    public function __construct(
        public string|null $minor = null,
        public string|null $major = null,
        public string|null $minorPhp = null,
        public string|null $majorPhp = null,
    ) {
    }
}
