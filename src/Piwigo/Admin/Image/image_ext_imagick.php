<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Image;

class image_ext_imagick implements imageInterface
{
    public string $imagickdir = '';

    /**
     * @var int|float
     */
    public $width = 0;

    /**
     * @var int|float
     */
    public $height = 0;

    /**
     * @var bool
     */
    public $is_animated_webp = false;

    /**
     * @var array<string, int|float|string|null>
     */
    public $commands = [];

    public function __construct(
        public string $source_filepath
    ) {
        /** @var array<string, mixed> $conf */
        global $conf;
        $imagick_dir = $conf['ext_imagick_dir'];
        $this->imagickdir = is_string($imagick_dir) ? $imagick_dir : '';

        $script_filename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if (is_string($script_filename) && str_starts_with($script_filename, '/kunden/')) {  // 1and1
            @putenv('MAGICK_THREAD_LIMIT=1');
        }

        if (strtolower(get_extension($this->source_filepath)) == 'webp') {
            $webp_info = pwg_image::webp_info($this->source_filepath);

            if ($webp_info['has-animation']) {
                $this->is_animated_webp = true;

                // ImageMagick "identify" returns the list of width x height for each
                // frame, such as "400x300400x300400x300" (3 frames of 400x300), as a big
                // string, impossible to parse :-/ So let's use the PHP embedded function
                // getimagesize here.
                $size = getimagesize($this->source_filepath);
                if ($size === false) {
                    throw new \Exception("image_ext_imagick(): getimagesize({$this->source_filepath}): Failed");
                }
                [$this->width, $this->height] = $size;
                return;
            }
        }

        $command = $this->imagickdir . 'identify -format "%wx%h" "' . realpath($this->source_filepath) . '"';
        @exec($command, $returnarray);
        if (empty($returnarray[0]) or ! (bool) preg_match('/^(\d+)x(\d+)$/', $returnarray[0], $match)) {
            die("[External ImageMagick] Corrupt image\n" . var_export($returnarray, true));
        }

        $this->width = (int) $match[1];
        $this->height = (int) $match[2];
    }

    public function add_command(string $command, int|float|string|null $params = null): void
    {
        $this->commands[$command] = $params;
    }

    #[\Override]
    public function get_width(): int|float
    {
        return $this->width;
    }

    #[\Override]
    public function get_height(): int|float
    {
        return $this->height;
    }

    #[\Override]
    public function crop(int|float $width, int|float $height, int|float $x, int|float $y): bool
    {
        $this->width = $width;
        $this->height = $height;

        // the final "!" is added to crop the canva too, for animated picture (with WebP in mind)
        $this->add_command('crop', $width . 'x' . $height . '+' . $x . '+' . $y . '!');
        return true;
    }

    #[\Override]
    public function strip(): bool
    {
        $this->add_command('strip');
        return true;
    }

    #[\Override]
    public function rotate(int|float $rotation): bool
    {
        if (empty($rotation)) {
            return true;
        }

        if ($rotation == 90 || $rotation == 270) {
            $tmp = $this->width;
            $this->width = $this->height;
            $this->height = $tmp;
        }
        $this->add_command('rotate', -$rotation);
        $this->add_command('orient', 'top-left');
        return true;
    }

    #[\Override]
    public function set_compression_quality(int $quality): bool
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($this->is_animated_webp) {
            // in cas of animated WebP, we need to maximize quality to 70 to avoid
            // heavy thumbnails (or square or whatever is displayed on the thumbnails
            // page)
            $max_quality = $conf['animated_webp_compression_quality'];
            $max_quality = is_numeric($max_quality) ? (int) $max_quality : $quality;
            $quality = min($quality, $max_quality);
        }

        $this->add_command('quality', $quality);
        return true;
    }

    #[\Override]
    public function resize(int|float $width, int|float $height): bool
    {
        $this->width = $width;
        $this->height = $height;

        $this->add_command('filter', 'Lanczos');
        $this->add_command('resize', $width . 'x' . $height . '!');
        return true;
    }

    #[\Override]
    public function sharpen(int|float $amount): bool
    {
        $m = pwg_image::get_sharpen_matrix($amount);

        $param = 'convolve "' . count($m) . ':';
        foreach ($m as $line) {
            $param .= ' ';
            $param .= implode(',', $line);
        }
        $param .= '"';
        $this->add_command('morphology', $param);
        return true;
    }

    #[\Override]
    public function compose(pwg_image $overlay, int|float $x, int|float $y, int|float $opacity): bool
    {
        // See image_imagick::compose()'s comment: only valid when both
        // images use the same backend, always true in practice.
        $overlay_backend = $overlay->image;
        if (! $overlay_backend instanceof self) {
            throw new \LogicException('pwg_image::compose(): overlay must use the same image backend');
        }
        $overlay_realpath = realpath($overlay_backend->source_filepath);
        if ($overlay_realpath === false) {
            throw new \Exception("compose(): unable to resolve overlay path {$overlay_backend->source_filepath}");
        }

        $param = 'compose dissolve -define compose:args=' . $opacity;
        $param .= ' ' . escapeshellarg($overlay_realpath);
        $param .= ' -gravity NorthWest -geometry +' . $x . '+' . $y;
        $param .= ' -composite';
        $this->add_command($param);
        return true;
    }

    #[\Override]
    public function write(string $destination_filepath): bool
    {
        // Set unconditionally by i.php / include/common.inc.php before any
        // code in this file runs (both entry points construct it during
        // bootstrap) — not a lazy-init global.
        /** @var \Logger $logger */
        global $logger;

        $this->add_command('interlace', 'line'); // progressive rendering
        // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
        //
        // option deactivated for Piwigo 2.4.1, it doesn't work fo old versions
        // of ImageMagick, see bug:2672. To reactivate once we have a better way
        // to detect IM version and when we know which version supports this
        // option
        //
        if (version_compare(pwg_image::$ext_imagick_version, '6.6') > 0) {
            $this->add_command('sampling-factor', '4:2:2');
        }

        $exec = $this->imagickdir . pwg_image::get_ext_imagick_command();
        $exec .= ' "' . realpath($this->source_filepath) . '"';

        // If the image is animated webp add a filter to avoid breaking the animation
        if ($this->is_animated_webp) {
            $exec .= ' -layers coalesce ';
        }

        foreach ($this->commands as $command => $params) {
            $exec .= ' -' . $command;
            if (! empty($params)) {
                $exec .= ' ' . $params;
            }
        }
        $dest = pathinfo($destination_filepath);
        if (! isset($dest['dirname'])) {
            throw new \Exception("write(): unable to determine directory for {$destination_filepath}");
        }
        $exec .= ' "' . realpath($dest['dirname']) . '/' . $dest['basename'] . '" 2>&1';
        $logger->debug($exec, 'i.php');
        @exec($exec, $returnarray);

        if (count($returnarray) > 0) {
            $logger->error('', 'i.php', $returnarray);
            foreach ($returnarray as $line) {
                trigger_error($line, E_USER_WARNING);
            }
        }
        return true;
    }
}
