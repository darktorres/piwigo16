<?php

declare(strict_types=1);

namespace Piwigo\Admin\Image;

// Define all needed methods for image class
interface imageInterface
{
    public function get_width();

    public function get_height();

    public function set_compression_quality($quality);

    public function crop($width, $height, $x, $y);

    public function strip();

    public function rotate($rotation);

    public function resize($width, $height);

    public function sharpen($amount);

    public function compose($overlay, $x, $y, $opacity);

    public function write($destination_filepath);
}
