<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;

/**
 * Named-disk configuration for StorageRegistry.
 *
 * Each entry is a lazy closure. To switch a disk to S3 or SFTP, replace the
 * LocalFilesystemAdapter with the appropriate Flysystem adapter and add the
 * matching composer package -- no call-site changes required.
 *
 * Disk roots use runtime Config values so they honour site-level overrides
 * (e.g. CurrentConfig::current()->uploadDir(), CurrentConfig::current()->dataLocation()). Required via
 * StorageRegistry::fromConfig(CurrentPaths::get()->root . 'config/storage.php')
 * -- no constructor/parameter seam available for a plain `require`d array
 * file, so $paths is captured once here the same way LegacyFileConf/
 * LegacyDbLayer/FileCombiner's static methods resolve Paths without DI.
 * 'local' is the effective (potentially PIWIGO_LOCAL_DIR-overridden)
 * site-local directory -- Paths::$siteLocal, not the always-'local/' Paths::$local.
 */
$paths = CurrentPaths::get();

return [
    // User photo uploads: upload/YYYY/MM/DD/
    'uploads' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(rtrim($paths->root . CurrentConfig::current()->uploadDir(), '/')),
    ),

    // Derivative/thumbnail tree: _data/i/
    'derivatives' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter($paths->root . CurrentConfig::current()->dataLocation() . 'i'),
    ),

    // Watermark PNG files: local/watermarks/
    'watermarks' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter($paths->siteLocal . 'watermarks'),
    ),

    // Theme files
    'themes' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter($paths->root . CurrentConfig::current()->themesDir()),
    ),

    // Plugin files
    'plugins' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(rtrim($paths->plugins, '/')),
    ),

    // Data exports
    'exports' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter($paths->root . CurrentConfig::current()->dataLocation() . 'exports'),
    ),

    // Site-local overrides: local/watermarks/, local/logo/, local/config/, …
    'local' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter($paths->siteLocal),
    ),

    // Temporary scratch space (chunk assembly, image processing)
    'temp' => static fn (): Filesystem => new Filesystem(
        new LocalFilesystemAdapter(sys_get_temp_dir() . '/piwigo'),
    ),
];
