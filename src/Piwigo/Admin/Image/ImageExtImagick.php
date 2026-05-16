<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;

final class ImageExtImagick implements ImageInterface
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
        $this->imagickdir = Config::extImagickDir();

        $script_filename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if (is_string($script_filename) && str_starts_with($script_filename, '/kunden/')) {  // 1and1
            if (function_exists('putenv')) {
                putenv('MAGICK_THREAD_LIMIT=1');
            }
        }

        if ('webp' == strtolower(pathinfo($this->source_filepath, PATHINFO_EXTENSION))) {
            $webp_info = PwgImage::webpInfo($this->source_filepath);

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

        $identify = PwgImage::getExtImagickCommand() === 'magick' ? 'magick identify' : 'identify';
        $realpathResult = realpath($this->source_filepath);
        $sourcePath = $realpathResult !== false ? $realpathResult : $this->source_filepath;
        $command = $this->imagickdir.$identify.' -format "%wx%h" "'.$sourcePath.'"';
        $returnarray = [];
        exec($command, $returnarray);
        if (empty($returnarray[0]) or !preg_match('/^(\d+)x(\d+)$/', $returnarray[0], $match)) {
            die("[External ImageMagick] Corrupt image\n" . var_export($returnarray, true));
        }

        $this->width = $match[1];
        $this->height = $match[2];
    }

    public function addCommand(string $command, string|int|null $params = null): void
    {
        $this->commands[$command] = $params;
    }

    #[\Override]
    public function getWidth(): int
    {
        return (int) $this->width;
    }

    #[\Override]
    public function getHeight(): int
    {
        return (int) $this->height;
    }

    #[\Override]
    public function crop(int $width, int $height, int $x, int $y): bool
    {
        $this->width = $width;
        $this->height = $height;

        // the final "!" is added to crop the canva too, for animated picture (with WebP in mind)
        $this->addCommand('crop', $width.'x'.$height.'+'.$x.'+'.$y.'!');
        return true;
    }

    #[\Override]
    public function strip(): bool
    {
        $this->addCommand('strip');
        return true;
    }

    #[\Override]
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
        $this->addCommand('rotate', -$rotation);
        $this->addCommand('orient', 'top-left');
        return true;
    }

    #[\Override]
    public function setCompressionQuality(int $quality): bool
    {
        if ($this->is_animated_webp) {
            // in cas of animated WebP, we need to maximize quality to 70 to avoid
            // heavy thumbnails (or square or whatever is displayed on the thumbnails
            // page)
            $quality = min($quality, Config::animatedWebpCompressionQuality());
        }

        $this->addCommand('quality', $quality);
        return true;
    }

    #[\Override]
    public function resize(int $width, int $height): bool
    {
        $this->width = $width;
        $this->height = $height;

        $this->addCommand('filter', 'Lanczos');
        $this->addCommand('resize', $width.'x'.$height.'!');
        return true;
    }

    #[\Override]
    public function sharpen(int $amount): bool
    {
        $m = PwgImage::getSharpenMatrix($amount);

        $param = 'convolve "'.count($m).':';
        foreach ($m as $line) {
            $param .= ' ';
            $param .= implode(',', $line);
        }
        $param .= '"';
        $this->addCommand('morphology', $param);
        return true;
    }

    #[\Override]
    public function compose(PwgImage $overlay, int $x, int $y, int $opacity): bool
    {
        if (!($overlay->image instanceof self)) {
            return false;
        }
        $param = 'compose dissolve -define compose:args='.$opacity;
        $overlay_path = realpath($overlay->source_filepath);
        $param .= ' '.escapeshellarg($overlay_path !== false ? $overlay_path : $overlay->source_filepath);
        $param .= ' -gravity NorthWest -geometry +'.$x.'+'.$y;
        $param .= ' -composite';
        $this->addCommand($param);
        return true;
    }

    #[\Override]
    public function write(string $destination_filepath): bool
    {
        $logger = LoggerRegistry::current();

        $this->addCommand('interlace', 'line'); // progressive rendering
        // use 4:2:2 chroma subsampling (reduce file size by 20-30% with "almost" no human perception)
        //
        // option deactivated for Piwigo 2.4.1, it doesn't work fo old versions
        // of ImageMagick, see bug:2672. To reactivate once we have a better way
        // to detect IM version and when we know which version supports this
        // option
        //
        if (version_compare(PwgImage::$ext_imagick_version, '6.6') > 0) {
            $this->addCommand('sampling-factor', '4:2:2');
        }

        $exec = $this->imagickdir.PwgImage::getExtImagickCommand();
        $realpathSrc = realpath($this->source_filepath);
        $exec .= ' "'.($realpathSrc !== false ? $realpathSrc : $this->source_filepath).'"';

        // If the image is animated webp add a filter to avoid breaking the animation
        if ($this->is_animated_webp) {
            $exec .= ' -layers coalesce ';
        }

        foreach ($this->commands as $command => $params) {
            $exec .= ' -'.$command;
            if (!empty($params) && is_scalar($params)) {
                $exec .= ' '.(string) $params;
            }
        }
        $dest = pathinfo($destination_filepath);
        $realDir = isset($dest['dirname']) ? realpath($dest['dirname']) : false;
        $dirname = $realDir !== false ? $realDir : ($dest['dirname'] ?? '');
        $exec .= ' "'.$dirname.'/'.$dest['basename'].'" 2>&1';
        $logger->debug($exec);
        exec($exec, $returnarray);

        if (count($returnarray) > 0) {
            $logger->error('imagick exec error', $returnarray);
        }
        return true;
    }
}
