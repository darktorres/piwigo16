<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

// +-----------------------------------------------------------------------+
// |                       Class for libvips library                       |
// +-----------------------------------------------------------------------+

use Jcupitt\Vips\Image;
use Jcupitt\Vips\FFI as VipsFFI;
use Override;

final class image_vips implements imageInterface
{
    /**
     * Per-thread initialization flag.
     * In PHP ZTS (threaded) mode, statics are per-thread — starts false in every new Apache worker thread.
     */
    private static bool $thread_initialized = false;

    public Image $image;

    public int $quality = 75;

    public string|false $source_filepath;

    /**
     * Serialise the first call to FFI::init() (which calls vips_init() internally)
     * across all concurrent Apache/mpm_winnt threads.
     *
     * vips_init() → g_type_init() is NOT thread-safe when called concurrently.
     * An exclusive file lock ensures only one thread runs the critical section
     * at a time. Subsequent threads call vips_init() after it has already
     * completed at the C level — it is idempotent once initialised.
     */
    private static function initVipsThread(): void
    {
        if (self::$thread_initialized) {
            return;
        }

        $lockPath = __DIR__ . '/../../_data/cache/vips_init.lock';
        $fp = fopen($lockPath, 'c');
        if ($fp === false) {
            throw new \RuntimeException('image_vips: cannot open ' . $lockPath);
        }

        flock($fp, LOCK_EX);
        try {
            VipsFFI::vips();                           // triggers FFI::init() → vips_init()
            VipsFFI::vips()->vips_cache_set_max(0);    // disable operation cache (prevents file handle retention)
            VipsFFI::vips()->vips_concurrency_set(1);  // disable libvips internal threads; outer pool owns parallelism
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        self::$thread_initialized = true;
    }

    public function __construct(
        string $source_filepath
    ) {
        self::initVipsThread();

        // putenv('VIPS_WARNING=0');
        $this->image = Image::newFromFile(realpath($source_filepath), [
            'access' => 'sequential',
        ]);
        $this->source_filepath = realpath($source_filepath);
    }

    public function add_command(
        string $command,
        ?array $params = null
    ): void {}

    #[Override]
    public function get_width(): int
    {
        return $this->image->width;
    }

    #[Override]
    public function get_height(): int
    {
        return $this->image->height;
    }

    #[Override]
    public function crop(
        int $width,
        int $height,
        int $x,
        int $y
    ): true {
        $this->image = $this->image->crop($x, $y, $width, $height);
        return true;
    }

    #[Override]
    public function strip(): true
    {
        return true;
    }

    #[Override]
    public function rotate(
        int $rotation
    ): true {
        $this->image = $this->image->rotate($rotation);
        return true;
    }

    #[Override]
    public function set_compression_quality(
        int $quality
    ): true {
        $this->quality = $quality;
        return true;
    }

    #[Override]
    public function resize(
        int $width,
        int $height
    ): true {
        $this->image = Image::thumbnail($this->source_filepath, $width, [
            'height' => $height,
        ]);
        return true;
    }

    #[Override]
    public function sharpen(
        int $amount
    ): true {
        return true;
    }

    #[Override]
    public function compose(
        pwg_image $overlay,
        int $x,
        int $y,
        int $opacity
    ): true {
        return true;
    }

    #[Override]
    public function write(
        string $destination_filepath
    ): true {
        $dest = pathinfo($destination_filepath);
        $this->image->writeToFile(realpath($dest['dirname']) . '/' . $dest['basename']);
        return true;
    }
}
