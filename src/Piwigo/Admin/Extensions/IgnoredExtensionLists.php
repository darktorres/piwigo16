<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

/** All ignored-update extension ids, partitioned by type. */
final readonly class IgnoredExtensionLists
{
    /**
     * @param list<string> $plugins
     * @param list<string> $themes
     * @param list<string> $languages
     */
    public function __construct(
        public array $plugins,
        public array $themes,
        public array $languages,
    ) {
    }
}
