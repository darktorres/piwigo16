<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Paths;

/**
 * Named-disk configuration for StorageRegistry.
 *
 * Each entry is a lazy closure. To switch a disk to S3 or SFTP, replace the
 * LocalFilesystemAdapter with the appropriate Flysystem adapter and add the
 * matching composer package -- no call-site changes required.
 *
 * Disk roots use runtime Config values so they honour site-level overrides
 * (e.g. $currentConfig->uploadDir(), $currentConfig->dataLocation()). This
 * file returns a closure so StorageRegistry::fromConfig() can pass
 * $paths/$currentConfig through as real params -- see that method's own
 * docblock. 'local' is the effective (potentially PIWIGO_LOCAL_DIR-overridden)
 * site-local directory -- Paths::$siteLocal, not the always-'local/'
 * Paths::$local.
 *
 * @return array<string, Closure():Filesystem>
 */
return static function (Paths $paths, CurrentConfig $currentConfig): array {
    return [
        // User photo uploads: upload/YYYY/MM/DD/
        'uploads' => static fn (): Filesystem => new Filesystem(
            new LocalFilesystemAdapter(rtrim($paths->root . $currentConfig->uploadDir(), '/')),
        ),

        // Derivative/thumbnail tree: _data/i/
        'derivatives' => static fn (): Filesystem => new Filesystem(
            new LocalFilesystemAdapter($paths->root . $currentConfig->dataLocation() . 'i'),
        ),

        // Watermark PNG files: local/watermarks/
        'watermarks' => static fn (): Filesystem => new Filesystem(
            new LocalFilesystemAdapter($paths->siteLocal . 'watermarks'),
        ),

        // Theme files
        'themes' => static fn (): Filesystem => new Filesystem(
            new LocalFilesystemAdapter($paths->root . $currentConfig->themesDir()),
        ),

        // Plugin files
        'plugins' => static fn (): Filesystem => new Filesystem(
            new LocalFilesystemAdapter(rtrim($paths->plugins, '/')),
        ),

        // Data exports
        'exports' => static fn (): Filesystem => new Filesystem(
            new LocalFilesystemAdapter($paths->root . $currentConfig->dataLocation() . 'exports'),
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
};
