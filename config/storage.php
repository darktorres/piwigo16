<?php

declare(strict_types=1);

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Piwigo\Config\Config;

/**
 * Named-disk configuration for StorageRegistry.
 *
 * Each entry is a lazy closure that returns a FilesystemOperator.  Swapping a
 * disk to a cloud backend only requires editing this file and adding the
 * matching Composer package — no call-site changes are needed.
 *
 * Disk roots use runtime Config values so they honour site-level overrides
 * (e.g. Config::uploadDir(), Config::dataLocation()).  PWG_LOCAL_DIR is the
 * 'local/' directory defined in include/common.inc.php.
 *
 * --- Switching `derivatives` to AWS S3 ---
 *
 *   composer require league/flysystem-aws-s3-v3
 *
 *   // In this file, replace the 'derivatives' closure with:
 *   'derivatives' => static fn (): Filesystem => new Filesystem(
 *       new \League\Flysystem\AwsS3V3\AwsS3V3Adapter(
 *           new \Aws\S3\S3Client([
 *               'region'  => getenv('AWS_REGION') ?: 'us-east-1',
 *               'version' => 'latest',
 *           ]),
 *           getenv('AWS_S3_BUCKET') ?: 'piwigo-derivatives',
 *           'prefix/path/in/bucket'   // optional key prefix
 *       )
 *   ),
 *
 * --- Switching `uploads` to SFTP ---
 *
 *   composer require league/flysystem-sftp-v3
 *
 *   'uploads' => static fn (): Filesystem => new Filesystem(
 *       new \League\Flysystem\PhpseclibV3\SftpAdapter(
 *           new \League\Flysystem\PhpseclibV3\SftpConnectionProvider(
 *               getenv('SFTP_HOST') ?: 'storage.example.com',
 *               getenv('SFTP_USER') ?: 'piwigo',
 *               privateKey: getenv('SFTP_KEY_PATH') ?: '/run/secrets/sftp_key',
 *           ),
 *           '/remote/uploads'
 *       )
 *   ),
 *
 * All other disks remain on local storage — only the closure changes.
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
