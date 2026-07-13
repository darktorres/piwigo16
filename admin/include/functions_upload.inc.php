<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\Upload\UploadService;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// add default event handler for image and thumbnail resize
add_event_handler('upload_image_resize', 'pwg_image_resize');
add_event_handler('upload_thumbnail_resize', 'pwg_image_resize');

// UploadService's 6 representative-generation handlers are `public
// static` specifically so this registration (which only ever runs once
// per process, via include_once) stays dedupe-safe forever -- see
// UploadService's own class docblock for why an instance-method callable
// would silently double-register.
add_event_handler('upload_file', [UploadService::class, 'uploadFilePdf']);
add_event_handler('upload_file', [UploadService::class, 'uploadFileHeic']);
add_event_handler('upload_file', [UploadService::class, 'uploadFileTiff']);
add_event_handler('upload_file', [UploadService::class, 'uploadFileVideo']);
add_event_handler('upload_file', [UploadService::class, 'uploadFilePsd']);
add_event_handler('upload_file', [UploadService::class, 'uploadFileEps']);

/**
 * @return array<string, array{default: bool|int, min: int|null, max: int|null, pattern: string|null, can_be_null: bool, error_message: string|null}>
 */
function get_upload_form_config(): array
{
    return new UploadService()
        ->getUploadFormConfig();
}

/**
 * @param array<string, mixed> $data
 * @param array<int, string> $errors
 * @param array<string, string> $form_errors
 */
function save_upload_form_config(array $data, array &$errors = [], array &$form_errors = []): bool
{
    return new UploadService()
        ->saveUploadFormConfig($data, $errors, $form_errors);
}

/**
 * @param int[]|null $categories
 */
function add_uploaded_file(string $source_filepath, ?string $original_filename = null, ?array $categories = null, ?int $level = null, ?int $image_id = null, ?string $original_md5sum = null): int|string
{
    return new UploadService()
        ->addUploadedFile($source_filepath, $original_filename, $categories, $level, $image_id, $original_md5sum);
}

function add_format(string $source_filepath, string $format_ext, int|string $format_of): string
{
    return new UploadService()
        ->addFormat($source_filepath, $format_ext, $format_of);
}

/**
 * @return array{width: int, height: int, filesize: float}
 */
function pwg_image_infos(string $path): array
{
    return new UploadService()
        ->pwgImageInfos($path);
}

/**
 * @return string[]
 */
function is_valid_image_extension(string $extension): array
{
    return new UploadService()
        ->isValidImageExtension($extension);
}

function file_upload_error_message(int $error_code): string
{
    return new UploadService()
        ->fileUploadErrorMessage($error_code);
}

function get_ini_size(string $ini_key, bool $in_bytes = true): int|string|false
{
    return new UploadService()
        ->getIniSize($ini_key, $in_bytes);
}

function add_upload_error(int|string $upload_id, string $error_message): void
{
    new UploadService()
        ->addUploadError($upload_id, $error_message);
}

function ready_for_upload_message(): ?string
{
    return new UploadService()
        ->readyForUploadMessage();
}
