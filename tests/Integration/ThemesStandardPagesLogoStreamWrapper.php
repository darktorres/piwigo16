<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

/**
 * See ThemesStandardPagesPageRendererTest's own docblock in
 * ThemesStandardPagesPageRendererTest.php for why this exists: a real
 * chmod'd file can't isolate the render()'s fopen()-only-fails
 * "save_error" branch (finfo_file() needs the exact same read access
 * fopen() does, so an unreadable file fails both calls identically and
 * only ever reaches the earlier "Invalid image file." branch -- confirmed
 * live, `php -r` against a chmod 0000 file returns false from both
 * finfo_file() and fopen()). Every method here is called reflectively by
 * PHP's own stream-wrapper engine (never referenced directly from PHP
 * code), matching the streamWrapper class signatures documented at
 * php.net/manual/en/class.streamwrapper.php: stream_open() serves real
 * PNG bytes (so finfo_file() reports 'image/png', same as any real
 * upload) on the first open and returns false on every open after that,
 * so the *second* real open of the same path (the renderer's own later
 * fopen() call) genuinely fails.
 */
final class ThemesStandardPagesLogoStreamWrapper
{
    /**
     * Set by PHP's own streams engine on every registered wrapper instance
     * (php.net/manual/en/class.streamwrapper.php) -- must be explicitly
     * declared or PHP 8.2+ raises a dynamic-property-creation deprecation
     * the moment the engine assigns it.
     */
    // Engine-managed, not tracked by the tool's StreamWrapperUsageProvider.
    // @phpstan-ignore shipmonk.deadProperty.neverRead
    public mixed $context = null;

    public static string $pngBytes = '';

    public static int $opens = 0;

    private string $buffer = '';

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        ++self::$opens;
        if (self::$opens > 1) {
            // 2nd+ open (the renderer's own later fopen() call) always
            // fails -- this is the entire point of this wrapper.
            return false;
        }

        $this->buffer = self::$pngBytes;
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [
            'size' => strlen($this->buffer),
            'mode' => 0100644,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function url_stat(string $path, int $flags): array
    {
        return [
            'mode' => 0100644,
            'size' => strlen(self::$pngBytes),
        ];
    }

    public function stream_cast(int $cast_as): bool
    {
        return false;
    }

    public function stream_close(): void {}
}
