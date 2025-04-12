<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\Config;

if (! extension_loaded('curl')) {
    exit('PHP extension "curl" is not loaded');
}

if (! extension_loaded('exif')) {
    exit('PHP extension "exif" is not loaded');
}

if (! extension_loaded('fileinfo')) {
    exit('PHP extension "fileinfo" is not loaded');
}

if (! extension_loaded('gd')) {
    exit('PHP extension "gd" is not loaded');
}

if (! extension_loaded('iconv')) {
    exit('PHP extension "iconv" is not loaded');
}

if (! extension_loaded('mbstring')) {
    exit('PHP extension "mbstring" is not loaded');
}

if (! extension_loaded('mysqli')) {
    exit('PHP extension "mysqli" is not loaded');
}

if (! extension_loaded('openssl')) {
    exit('PHP extension "openssl" is not loaded');
}

if (! extension_loaded('tokenizer')) {
    exit('PHP extension "tokenizer" is not loaded');
}

require_once __DIR__ . '/../vendor/autoload.php';
// require_once __DIR__ . '/../coverage.php';

$conf = new Config();
