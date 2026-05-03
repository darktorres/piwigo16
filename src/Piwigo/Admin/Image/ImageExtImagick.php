<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

class ImageExtImagick implements ImageInterface
{
    public string $imagickdir = '';
    /** @var string|int */
    public $width = '';
    /** @var string|int */
    public $height = '';
    /** @var bool */
    public $is_animated_webp = false;
    /** @var array<mixed> */
    public array $commands = [];

    public function __construct(public string $source_filepath)
    {
        $this->imagickdir = \Piwigo\Config\Config::extImagickDir();

        $script_filename = @$_SERVER['SCRIPT_FILENAME'];
        if (is_string($script_filename) && str_starts_with($script_filename, '/kunden/')) {  // 1and1
            @putenv('MAGICK_THREAD_LIMIT=1');
        }

        if ('webp' == strtolower(get_extension($this->source_filepath))) {
            $webp_info = PwgImage::webp_info($this->source_filepath);

            if ($webp_info['has-animation']) {
                $this->is_animated_webp = true;

                // ImageMagick "identify" returns the list of width x height for each
                // frame, such as "400x300400x300400x300" (3 frames of 400x300), as a big
                // string, impossible to parse :-/ So let's use the PHP embedded function
                // getimagesize here.
                [$this->width, $this->height] = getimagesize($this->source_filepath) ?: [0, 0];
                return;
            }
        }

        $identify = PwgImage::get_ext_imagick_command() === 'magick' ? 'magick identify' : 'identify';
        $command = $this->imagickdir.$identify.' -format "%wx%h" "'.realpath($this->source_filepath).'"';
        @exec($command, $returnarray);
        if (empty($returnarray[0]) or !preg_match('/^(\d+)x(\d+)$/', $returnarray[0], $match)) {
            die("[External ImageMagick] Corrupt image\n" . var_export($returnarray, true));
        }

        $this->width = $match[1];
        $this->height = $match[2];
    }

    public function add_command(string $command, mixed $params = null): void
    {
        $this->commands[$command] = $params;
    }

    public function get_width(): int
    {
        return (int) $this->width;
    }

    public function get_height(): int
    {
        return (int) $this->height;
    }

    public function crop(int $width, int $height, int $x, int $y): bool
    {
        $this->width = $width;
        $this->height = $height;

        // the final "!" is added to crop the canva too, for animated picture (with WebP in mind)
        $this->add_command('crop', $width.'x'.$height.'+'.$x.'+'.$y.'!');
        return true;
    }

    public function strip(): bool
    {
        $this->add_command('strip');
        return true;
    }

    public function rotate(int $rotation): bool
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

    public function set_compression_quality(int $quality): bool
    {
        if ($this->is_animated_webp) {
            // in cas of animated WebP, we need to maximize quality to 70 to avoid
            // heavy thumbnails (or square or whatever is displayed on the thumbnails
            // page)
            $quality = min($quality, \Piwigo\Config\Config::animatedWebpCompressionQuality());
        }

        $this->add_command('quality', $quality);
        return true;
    }

    public function resize(int $width, int $height): bool
    {
        $this->width = $width;
        $this->height = $height;

        $this->add_command('filter', 'Lanczos');
        $this->add_command('resize', $width.'x'.$height.'!');
        return true;
    }

    public function sharpen(int $amount): bool
    {
        $m = PwgImage::get_sharpen_matrix($amount);

        $param = 'convolve "'.count($m).':';
        foreach ($m as $line) {
            $param .= ' ';
            $param .= implode(',', $line);
        }
        $param .= '"';
        $this->add_command('morphology', $param);
        return true;
    }

    public function compose(mixed $overlay, int $x, int $y, int $opacity): bool
    {
        if (!($overlay instanceof ImageExtImagick)) {
            return false;
        }
        $param = 'compose dissolve -define compose:args='.$opacity;
        $overlay_path = realpath($overlay->source_filepath);
        $param .= ' '.escapeshellarg($overlay_path !== false ? $overlay_path : $overlay->source_filepath);
        $param .= ' -gravity NorthWest -geometry +'.$x.'+'.$y;
        $param .= ' -composite';
        $this->add_command($param);
        return true;
    }

    public function write(string $destination_filepath): bool
    {
        $logger = \Piwigo\Core\LoggerRegistry::current();

        $this->add_command('interlace', 'line'); // progressive rendering
        // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
        //
        // option deactivated for Piwigo 2.4.1, it doesn't work fo old versions
        // of ImageMagick, see bug:2672. To reactivate once we have a better way
        // to detect IM version and when we know which version supports this
        // option
        //
        if (version_compare(PwgImage::$ext_imagick_version, '6.6') > 0) {
            $this->add_command('sampling-factor', '4:2:2');
        }

        $exec = $this->imagickdir.PwgImage::get_ext_imagick_command();
        $exec .= ' "'.realpath($this->source_filepath).'"';

        // If the image is animated webp add a filter to avoid breaking the animation
        if ($this->is_animated_webp) {
            $exec .= ' -layers coalesce ';
        }

        foreach ($this->commands as $command => $params) {
            $exec .= ' -'.$command;
            if (!empty($params) && is_scalar($params)) {
                $exec .= ' '.$params;
            }
        }
        $dest = pathinfo((string) $destination_filepath);
        $dirname = isset($dest['dirname']) ? (realpath($dest['dirname']) ?: $dest['dirname']) : '';
        $exec .= ' "'.$dirname.'/'.$dest['basename'].'" 2>&1';
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
