<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Piwigo\Config\Config;

/**
 * Named-disk configuration for StorageRegistry.
 *
 * Each entry is a lazy closure.  To switch a disk to S3 or SFTP, replace the
 * LocalFilesystemAdapter with the appropriate Flysystem adapter and add the
 * matching composer package — no call-site changes required.
 *
 * Disk roots use runtime Config values so they honour site-level overrides
 * (e.g. Config::uploadDir(), Config::dataLocation()).  PWG_LOCAL_DIR is the
 * 'local/' directory defined in include/common.inc.php.
 */
return [
    // User photo uploads: ./upload/YYYY/MM/DD/
    'uploads' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(rtrim(PHPWG_ROOT_PATH . Config::uploadDir(), '/'))
    ),

    // Derivative/thumbnail tree: _data/i/
    'derivatives' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(PHPWG_ROOT_PATH . Config::dataLocation() . 'i')
    ),

    // Watermark PNG files: local/watermarks/
    'watermarks' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'watermarks')
    ),

    // Theme files
    'themes' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(PHPWG_ROOT_PATH . ltrim(Config::themesDir(), './'))
    ),

    // Plugin files
    'plugins' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(PHPWG_ROOT_PATH . 'plugins')
    ),

    // Data exports
    'exports' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(PHPWG_ROOT_PATH . Config::dataLocation() . 'exports')
    ),

    // Site-local overrides: local/watermarks/, local/logo/, local/config/, …
    'local' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(PHPWG_ROOT_PATH . PWG_LOCAL_DIR)
    ),

    // Temporary scratch space (chunk assembly, image processing)
    'temp' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(sys_get_temp_dir() . '/piwigo')
    ),
];
