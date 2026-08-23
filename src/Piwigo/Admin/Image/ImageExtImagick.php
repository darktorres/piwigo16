<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Image;

use Exception;
use LogicException;
use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\StringHelper;

final class ImageExtImagick implements ImageInterface
{
    public string $imagickdir = '';

    public int|float $width = 0;

    public int|float $height = 0;

    public bool $is_animated_webp = false;

    /**
     * @var array<string, int|float|string|null>
     */
    public array $commands = [];

    public function __construct(
        public string $source_filepath,
        private readonly CurrentLogger $currentLogger,
        private readonly CurrentConfig $currentConfig,
    ) {
        $imagick_dir = $this->currentConfig->extImagickDir;
        $this->imagickdir = $imagick_dir;

        if (strtolower(StringHelper::getExtension($this->source_filepath)) === 'webp') {
            $webp_info = ImageBackend::webpInfo($this->source_filepath);

            if ($webp_info->hasAnimation) {
                $this->is_animated_webp = true;

                // ImageMagick "identify" returns the list of width x height for each
                // frame, such as "400x300400x300400x300" (3 frames of 400x300), as a big
                // string, impossible to parse :-/ So let's use the PHP embedded function
                // getimagesize here.
                $size = getimagesize($this->source_filepath);
                if ($size === false) {
                    throw new Exception("ImageExtImagick(): getimagesize({$this->source_filepath}): Failed");
                }
                [$this->width, $this->height] = $size;
                return;
            }
        }

        // [SEC-16] escapeshellarg() on both the dir prefix and the real
        // file path -- the naive '"' . ... . '"' quoting this replaces
        // never escaped an embedded '"' or shell metacharacter in the path.
        //
        // "< /dev/null" keeps identify from ever blocking on stdin -- a
        // vanished/unreadable source path makes escapeshellarg() quote an
        // empty string, and identify treats a missing filename as "read
        // the image from stdin" instead of failing fast; without this
        // redirect, a process whose own stdin is left open (as under
        // pest-plugin-mutate's worker harness, unlike a normal CLI run)
        // hangs here indefinitely instead of reaching the Corrupt-image
        // exception below.
        //
        // "2>/dev/null" (not write()'s own "2>&1" a few methods down) --
        // non-fatal ImageMagick warnings (e.g. a PNG with a minor IDAT CRC
        // mismatch) go to stderr; merging them into $returnarray the way
        // write() does would risk pushing the "%wx%h" line below index 0,
        // which the parse below reads positionally. Discarding stderr here
        // keeps stdout clean and stops those warnings from otherwise
        // inheriting this process's stderr into the web server's error log.
        $command = escapeshellarg($this->imagickdir) . 'identify -format "%wx%h" '
            . escapeshellarg((string) realpath($this->source_filepath)) . ' < /dev/null 2>/dev/null';
        $returnarray = [];
        @exec($command, $returnarray);
        if (! isset($returnarray[0]) || $returnarray[0] === '' || $returnarray[0] === '0' or ! (bool) preg_match('/^(\d+)x(\d+)$/', $returnarray[0], $match)) {
            throw new ImageProcessingException("[External ImageMagick] Corrupt image\n" . var_export($returnarray, true));
        }

        $this->width = (int) $match[1];
        $this->height = (int) $match[2];
    }

    public function addCommand(string $command, int|float|string|null $params = null): void
    {
        $this->commands[$command] = $params;
    }

    #[Override]
    public function getWidth(): int|float
    {
        return $this->width;
    }

    #[Override]
    public function getHeight(): int|float
    {
        return $this->height;
    }

    #[Override]
    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        $this->width = $width;
        $this->height = $height;

        // the final "!" is added to crop the canva too, for animated picture (with WebP in mind)
        $this->addCommand('crop', (string) $width . 'x' . (string) $height . '+' . (string) $x . '+' . (string) $y . '!');
        return true;
    }

    #[Override]
    public function strip(): bool
    {
        $this->addCommand('strip');
        return true;
    }

    #[Override]
    public function rotate(int|float $rotation): bool
    {
        if ($rotation === 0 || $rotation === 0.0) {
            return true;
        }

        if ((float) $rotation === 90.0 || (float) $rotation === 270.0) {
            $tmp = $this->width;
            $this->width = $this->height;
            $this->height = $tmp;
        }
        $this->addCommand('rotate', -$rotation);
        $this->addCommand('orient', 'top-left');
        return true;
    }

    #[Override]
    public function setCompressionQuality(int $quality): bool
    {

        if ($this->is_animated_webp) {
            // in cas of animated WebP, we need to maximize quality to 70 to avoid
            // heavy thumbnails (or square or whatever is displayed on the thumbnails
            // page)
            $max_quality = $this->currentConfig->animatedWebpCompressionQuality;
            $quality = min($quality, $max_quality);
        }

        $this->addCommand('quality', $quality);
        return true;
    }

    #[Override]
    public function resize(int|float $width, int|float $height): bool
    {
        $this->width = $width;
        $this->height = $height;

        $this->addCommand('filter', 'Lanczos');
        $this->addCommand('resize', (string) $width . 'x' . (string) $height . '!');
        return true;
    }

    #[Override]
    public function sharpen(int|float $amount): bool
    {
        $m = ImageBackend::getSharpenMatrix($amount);

        $param = 'convolve "' . count($m) . ':';
        foreach ($m as $line) {
            $param .= ' ';
            $param .= implode(',', $line);
        }
        $param .= '"';
        $this->addCommand('morphology', $param);
        return true;
    }

    #[Override]
    public function compose(ImageBackend $overlay, int|float $x, int|float $y, int|float $opacity): bool
    {
        // See ImageImagick::compose()'s comment: only valid when both
        // images use the same backend, always true in practice.
        $overlay_backend = $overlay->image;
        if (! $overlay_backend instanceof self) {
            throw new LogicException('ImageBackend::compose(): overlay must use the same image backend');
        }
        $overlay_realpath = realpath($overlay_backend->source_filepath);
        if ($overlay_realpath === false) {
            throw new Exception("compose(): unable to resolve overlay path {$overlay_backend->source_filepath}");
        }

        $param = 'compose dissolve -define compose:args=' . (string) $opacity;
        $param .= ' ' . escapeshellarg($overlay_realpath);
        $param .= ' -gravity NorthWest -geometry +' . (string) $x . '+' . (string) $y;
        $param .= ' -composite';
        $this->addCommand($param);
        return true;
    }

    #[Override]
    public function write(string $destination_filepath): bool
    {
        // Set unconditionally by i.php / include/common.inc.php before any
        // code in this file runs (both entry points construct it during
        // bootstrap) — not a lazy-init global.
        $logger = $this->currentLogger->get();

        $this->addCommand('interlace', 'line'); // progressive rendering
        // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
        //
        // option deactivated for Piwigo 2.4.1, it doesn't work fo old versions
        // of ImageMagick, see bug:2672. To reactivate once we have a better way
        // to detect IM version and when we know which version supports this
        // option
        //
        if (version_compare(ImageBackend::$ext_imagick_version, '6.6') > 0) {
            $this->addCommand('sampling-factor', '4:2:2');
        }

        // [SEC-16] escapeshellarg() on the dir prefix and both real paths
        // below -- see the constructor's own note above.
        $exec = escapeshellarg($this->imagickdir) . ImageBackend::getExtImagickCommand();
        $exec .= ' ' . escapeshellarg((string) realpath($this->source_filepath));

        // If the image is animated webp add a filter to avoid breaking the animation
        if ($this->is_animated_webp) {
            $exec .= ' -layers coalesce ';
        }

        foreach ($this->commands as $command => $params) {
            $exec .= ' -' . $command;
            if (! in_array($params, [null, 0, 0.0, '0', ''], true)) {
                $exec .= ' ' . (string) $params;
            }
        }
        $dest = pathinfo($destination_filepath);
        if (! isset($dest['dirname'])) {
            throw new Exception("write(): unable to determine directory for {$destination_filepath}");
        }
        $dest_dirname_realpath = realpath($dest['dirname']);
        if ($dest_dirname_realpath === false) {
            throw new Exception("write(): unable to resolve directory {$dest['dirname']}");
        }
        // "< /dev/null" -- same stdin-hang guard as construct()'s own
        // identify call above.
        $exec .= ' ' . escapeshellarg($dest_dirname_realpath . '/' . $dest['basename']) . ' < /dev/null 2>&1';
        $logger->debug($exec, 'i.php');
        @exec($exec, $returnarray);

        if (count($returnarray) > 0) {
            $logger->error('', 'i.php', $returnarray);
        }
        return true;
    }
}
