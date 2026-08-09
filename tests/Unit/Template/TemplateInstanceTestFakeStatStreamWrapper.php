<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Template;

/**
 * Stream wrapper isolating set_extents()'s own "file_exists() passes but
 * realpath() still fails" branch (Template.php's own
 * `if ($real_path !== false)` guard around the extents assignment).
 * realpath() never supports stream-wrapped schemes -- it only ever
 * resolves real local filesystem paths -- so url_stat() reporting a real,
 * existing regular file is enough to make file_exists() succeed on a
 * scheme:// path while realpath() on that exact same path is
 * unconditionally false, the same "passes the earlier guard, fails
 * downstream" isolation technique
 * tests/Unit/Image/ImageServiceTestFailedOpenStreamWrapper.php's own
 * docblock describes for an analogous branch.
 */
final class TemplateInstanceTestFakeStatStreamWrapper
{
    /**
     * Set by PHP's own streams engine on every registered wrapper
     * instance -- must be explicitly declared or PHP 8.2+ raises a
     * dynamic-property-creation deprecation the moment the engine
     * assigns it.
     */
    // shipmonk/dead-code-detector's StreamWrapperUsageProvider tracks
    // reflective method calls the streams engine makes, but not this
    // engine-managed $context property assignment -- see class docblock.
    // @phpstan-ignore shipmonk.deadProperty.neverRead
    public mixed $context = null;

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100644, 'size' => 0];
    }
}
