<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Image;

/**
 * Stream wrapper for countPdfPages()'s own "is_file()/is_readable() both
 * pass, but file_get_contents() itself still fails" branch -- url_stat()
 * reports a real, readable regular file (mode 0100644, matching a normal
 * on-disk file), so both guards ahead of file_get_contents() pass, but
 * stream_open() unconditionally returns false so the read itself fails
 * with a real, unsuppressed "Failed to open stream" warning, same as
 * ThemesStandardPagesLogoStreamWrapper's own precedent for isolating an
 * analogous "passes the earlier guard, fails the later I/O call" branch.
 * Every method here is called reflectively by PHP's own stream-wrapper
 * engine, per php.net/manual/en/class.streamwrapper.php.
 */
final class ImageServiceTestFailedOpenStreamWrapper
{
    /**
     * Set by PHP's own streams engine on every registered wrapper
     * instance -- must be explicitly declared or PHP 8.2+ raises a
     * dynamic-property-creation deprecation the moment the engine
     * assigns it.
     */
    // Engine-managed, not tracked by the tool's StreamWrapperUsageProvider.
    // @phpstan-ignore shipmonk.deadProperty.neverRead
    public mixed $context = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return false;
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return [
            'mode' => 0100644,
            'size' => 0,
        ];
    }
}
